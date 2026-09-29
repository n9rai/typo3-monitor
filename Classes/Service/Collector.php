<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sammelt die Kennzahlen fuer den Report (Schema n9c.agent.report/1,
 * siehe inside-monitor/PROTOCOL.md und inside-monitor/checks.yaml).
 *
 * Grundsaetze:
 * - Nur Zahlen und Flags - keine Benutzernamen, E-Mails, IPs, Inhalte.
 * - Nur lesen, nie etwas an der Instanz veraendern.
 * - Jeder Check ist einzeln abgesichert: schlaegt einer fehl, wird er mit
 *   null gemeldet (= "nicht geprueft"), der Rest des Reports geht trotzdem raus.
 */
final class Collector
{
    public const AGENT_NAME = 'n9c_monitor';
    public const AGENT_VERSION = '0.3.3';
    public const SCHEMA = 'n9c.agent.report/1';

    private const INACTIVE_DAYS = 90;
    private const MAX_SCANNED_FILES = 200000;
    /** Zeitbudget fuer den fileadmin-Scan: CLI grosszuegig, beim Auto-Report im Seitenaufruf knapp */
    private const SCAN_SECONDS_FULL = 60;
    private const SCAN_SECONDS_QUICK = 5;
    private const SUSPICIOUS_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht'];

    /** @var array<string, string> Fehler je Check, landen in cms_specific.collector_errors */
    private array $errors = [];

    private int $scanDeadline = 0;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PackageManager $packageManager,
        private readonly SiteFinder $siteFinder,
        private readonly Registry $registry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(bool $quick = false): array
    {
        $this->errors = [];
        $this->scanDeadline = time() + ($quick ? self::SCAN_SECONDS_QUICK : self::SCAN_SECONDS_FULL);
        $typo3Version = (new Typo3Version())->getVersion();
        $admins = $this->safe('admins', fn () => $this->loadAdmins());
        $suspicious = $this->safe('fs.php_in_upload_dir', fn () => $this->findSuspiciousUploadFiles());

        $checks = [
            'auth.admin_without_mfa' => $admins === null ? null : count(array_filter($admins, fn ($a) => !$a['mfa'])),
            'auth.admin_count' => $admins === null ? null : count($admins),
            'auth.default_admin_username' => $this->safe('auth.default_admin_username', fn () => $this->hasDefaultAdminUsername()),
            'auth.inactive_admins_90d' => $admins === null ? null : count(array_filter($admins, fn ($a) => $a['inactive'])),
            'auth.failed_logins_24h' => $this->safe('auth.failed_logins_24h', fn () => $this->countFailedLogins()),
            'config.debug_output_enabled' => $this->safe('config.debug_output_enabled', fn () => $this->isDebugOutputEnabled()),
            'config.backend_https_enforced' => $this->safe('config.backend_https_enforced', fn () => $this->isBackendHttpsEnforced()),
            'fs.php_in_upload_dir' => $suspicious === null ? null : count($suspicious),
            'ops.scheduler_last_run_hours' => $this->safe('ops.scheduler_last_run_hours', fn () => $this->schedulerLastRunHours()),
            'cms.typo3.install_tool_enabled' => $this->safe('cms.typo3.install_tool_enabled', fn () => $this->isInstallToolEnabled()),
            'cms.typo3.trusted_hosts_wildcard' => $this->safe('cms.typo3.trusted_hosts_wildcard', fn () => $this->isTrustedHostsWildcard()),
        ];

        $report = [
            'schema' => self::SCHEMA,
            'agent' => ['name' => self::AGENT_NAME, 'version' => self::AGENT_VERSION],
            'cms' => [
                'type' => 'typo3',
                'version' => $typo3Version,
                'context' => (string)Environment::getContext(),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'db' => $this->safe('runtime.db', fn () => $this->databaseVersion()),
                'os' => PHP_OS_FAMILY,
            ],
            'packages' => $this->safe('packages', fn () => $this->collectPackages()) ?? [],
            'checks' => array_map(
                static fn (string $id, $value) => ['id' => $id, 'value' => $value],
                array_keys($checks),
                array_values($checks)
            ),
            'sites' => $this->safe('sites', fn () => $this->collectSites()) ?? [],
            'cms_specific' => [
                'composer_mode' => Environment::isComposerMode(),
                // Relative Pfade (max. 20) verdaechtiger Dateien - nur zur Anzeige
                'suspicious_upload_files' => array_slice($suspicious ?? [], 0, 20),
            ],
        ];
        if ($this->errors !== []) {
            $report['cms_specific']['collector_errors'] = $this->errors;
        }
        return $report;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T|null
     */
    private function safe(string $name, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->errors[$name] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
            return null;
        }
    }

    // --- Backend-Benutzer ----------------------------------------------------

    /**
     * Aktive Admins (Standard-Restriktionen: nicht geloescht, nicht
     * deaktiviert, innerhalb Start-/Endzeit). Es verlassen nur Flags diese
     * Methode, keine Namen.
     *
     * @return list<array{mfa: bool, inactive: bool}>
     */
    private function loadAdmins(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $qb->select('uid', 'lastlogin', 'crdate', 'mfa')
            ->from('be_users')
            ->where($qb->expr()->eq('admin', $qb->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();

        $cutoff = time() - self::INACTIVE_DAYS * 86400;
        $admins = [];
        foreach ($rows as $row) {
            $lastLogin = (int)($row['lastlogin'] ?? 0);
            $created = (int)($row['crdate'] ?? 0);
            $admins[] = [
                'mfa' => $this->hasActiveMfa($row['mfa'] ?? null),
                'inactive' => $lastLogin > 0 ? $lastLogin < $cutoff : ($created > 0 && $created < $cutoff),
            ];
        }
        return $admins;
    }

    private function hasActiveMfa(mixed $mfa): bool
    {
        if (!is_string($mfa) || $mfa === '') {
            return false;
        }
        $providers = json_decode($mfa, true);
        if (!is_array($providers)) {
            return false;
        }
        foreach ($providers as $provider) {
            if (is_array($provider) && !empty($provider['active'])) {
                return true;
            }
        }
        return false;
    }

    private function hasDefaultAdminUsername(): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $count = $qb->count('uid')
            ->from('be_users')
            ->where($qb->expr()->eq('username', $qb->createNamedParameter('admin')))
            ->executeQuery()
            ->fetchOne();
        return (int)$count > 0;
    }

    private function countFailedLogins(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from('sys_log')
            ->where(
                // type 255 = Login, action 3 = fehlgeschlagener Versuch (SysLog\Action\Login::ATTEMPT)
                $qb->expr()->eq('type', $qb->createNamedParameter(255, Connection::PARAM_INT)),
                $qb->expr()->eq('action', $qb->createNamedParameter(3, Connection::PARAM_INT)),
                $qb->expr()->gte('tstamp', $qb->createNamedParameter(time() - 86400, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
        return (int)$count;
    }

    // --- Konfiguration -------------------------------------------------------

    private function isDebugOutputEnabled(): bool
    {
        $sys = $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [];
        $displayErrors = (int)($sys['displayErrors'] ?? -1);
        $devIpMask = trim((string)($sys['devIPmask'] ?? ''));
        if ($displayErrors === 1) {
            return true;
        }
        // -1 = abhaengig von devIPmask: "*" bedeutet fuer alle Besucher
        return $displayErrors === -1 && $devIpMask === '*';
    }

    private function isBackendHttpsEnforced(): ?bool
    {
        $be = $GLOBALS['TYPO3_CONF_VARS']['BE'] ?? [];
        if (!array_key_exists('lockSSL', $be)) {
            return null;
        }
        return (bool)$be['lockSSL'];
    }

    private function isTrustedHostsWildcard(): bool
    {
        $pattern = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] ?? ''));
        return in_array($pattern, ['.*', '.+', '^.*$', '^.+$'], true);
    }

    /**
     * Nachgebaut statt EnableFileService::checkInstallToolEnableFile()
     * aufzurufen - die Core-Methode loescht abgelaufene Dateien bzw.
     * verlaengert deren Laufzeit, der Monitor soll aber nichts veraendern.
     * Logik: Datei vorhanden UND (Inhalt "KEEP_FILE" ODER juenger als 1 h).
     */
    private function isInstallToolEnabled(): bool
    {
        $candidates = [
            Environment::getVarPath() . '/transient/ENABLE_INSTALL_TOOL',
            Environment::getConfigPath() . '/ENABLE_INSTALL_TOOL',
        ];
        if (method_exists(Environment::class, 'getLegacyConfigPath')) {
            $candidates[] = rtrim(Environment::getLegacyConfigPath(), '/') . '/ENABLE_INSTALL_TOOL';
        }
        foreach (array_unique($candidates) as $file) {
            if (!@is_file($file)) {
                continue;
            }
            if (trim((string)@file_get_contents($file)) === 'KEEP_FILE') {
                return true;
            }
            if ((int)@filemtime($file) > time() - 3600) {
                return true;
            }
        }
        return false;
    }

    // --- Dateisystem ---------------------------------------------------------

    /**
     * @return list<string> relative Pfade ausfuehrbarer PHP-Dateien in fileadmin/
     */
    private function findSuspiciousUploadFiles(): array
    {
        $root = Environment::getPublicPath() . '/fileadmin';
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        $scanned = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $file) {
            if (++$scanned > self::MAX_SCANNED_FILES) {
                $this->errors['fs.php_in_upload_dir'] = 'Scan nach ' . self::MAX_SCANNED_FILES . ' Dateien abgebrochen';
                break;
            }
            if ($scanned % 500 === 0 && time() > $this->scanDeadline) {
                $this->errors['fs.php_in_upload_dir'] = 'Scan nach Zeitbudget abgebrochen (' . $scanned . ' Dateien geprueft)';
                break;
            }
            /** @var \SplFileInfo $file */
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), self::SUSPICIOUS_EXTENSIONS, true)) {
                $found[] = 'fileadmin/' . ltrim(substr($file->getPathname(), strlen($root)), '/');
            }
        }
        return $found;
    }

    // --- Betrieb -------------------------------------------------------------

    private function schedulerLastRunHours(): ?int
    {
        $lastRun = $this->registry->get('tx_scheduler', 'lastRun');
        if (!is_array($lastRun) || empty($lastRun['end'])) {
            return null;
        }
        return (int)floor((time() - (int)$lastRun['end']) / 3600);
    }

    private function databaseVersion(): ?string
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (method_exists($connection, 'getServerVersion')) {
            return mb_substr((string)$connection->getServerVersion(), 0, 60);
        }
        return null;
    }

    // --- Pakete & Sites --------------------------------------------------------

    /**
     * Composer-Modus: alle installierten Composer-Pakete.
     * Klassischer Modus: alle aktiven Nicht-Core-Extensions, jeweils als
     * typo3-ter (Extension-Key) UND - falls die Extension einen Composer-
     * Namen hat - als composer, weil die Advisory-Datenbank nach
     * Composer-Namen gefuehrt wird. Den Core selbst leitet das Backend
     * aus cms.version ab.
     *
     * @return list<array{ecosystem: string, name: string, version: string}>
     */
    private function collectPackages(): array
    {
        $packages = [];
        if (Environment::isComposerMode() && class_exists(\Composer\InstalledVersions::class)) {
            foreach (\Composer\InstalledVersions::getInstalledPackages() as $name) {
                $version = \Composer\InstalledVersions::getPrettyVersion($name);
                if ($version === null || $version === '') {
                    continue;
                }
                $packages[] = ['ecosystem' => 'composer', 'name' => strtolower($name), 'version' => ltrim($version, 'v')];
            }
            return $packages;
        }

        $frameworkPath = rtrim((string)realpath(Environment::getFrameworkBasePath()), '/') . '/';
        foreach ($this->packageManager->getActivePackages() as $package) {
            /** @var PackageInterface $package */
            $path = (string)realpath($package->getPackagePath());
            if ($frameworkPath !== '/' && str_starts_with($path . '/', $frameworkPath)) {
                continue; // System-Extension -> steckt in cms.version
            }
            $key = $package->getPackageKey();
            $version = $this->packageVersion($package);
            if ($version === null) {
                continue;
            }
            $packages[] = ['ecosystem' => 'typo3-ter', 'name' => $key, 'version' => $version];
            $composerName = $package->getValueFromComposerManifest('name');
            if (is_string($composerName) && str_contains($composerName, '/')) {
                $packages[] = ['ecosystem' => 'composer', 'name' => strtolower($composerName), 'version' => $version];
            }
        }
        return $packages;
    }

    private function packageVersion(PackageInterface $package): ?string
    {
        $version = (string)$package->getPackageMetaData()->getVersion();
        if ($version !== '' && !str_contains($version, 'no-version-set')) {
            return ltrim($version, 'v');
        }
        // Aeltere Extensions ohne Version in composer.json: ext_emconf.php lesen
        $emConfFile = rtrim($package->getPackagePath(), '/') . '/ext_emconf.php';
        if (is_file($emConfFile)) {
            $_EXTKEY = $package->getPackageKey();
            $EM_CONF = [];
            include $emConfFile;
            $version = (string)($EM_CONF[$_EXTKEY]['version'] ?? '');
            if ($version !== '') {
                return ltrim($version, 'v');
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function collectSites(): array
    {
        $sites = [];
        // Ohne Cache: Wird config/sites/ direkt geaendert (FTP/Deployment statt
        // Sites-Modul), liefert der Cache sonst noch den alten Stand - und das
        // Backend wuerde laengst entfernte Domains weiter scannen.
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            $base = (string)$site->getBase();
            if ($base !== '' && $base !== '/') {
                $sites[] = mb_substr($base, 0, 255);
            }
        }
        return array_values(array_unique($sites));
    }
}
