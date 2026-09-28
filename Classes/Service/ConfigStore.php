<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Speichert Instanz-ID, Secret und Endpunkt - bewusst NICHT in der
 * Datenbank (siehe inside-monitor/PROTOCOL.md).
 *
 * Reihenfolge:
 * 1. Umgebungsvariablen N9C_MONITOR_INSTANCE / N9C_MONITOR_SECRET /
 *    N9C_MONITOR_ENDPOINT (empfohlen, wenn der Hoster das erlaubt)
 * 2. PHP-Datei <config-Pfad>/n9c_monitor.php (klassischer Modus:
 *    typo3conf/n9c_monitor.php). Als PHP-Datei mit "return [...]" liefert
 *    ein direkter Aufruf ueber den Browser nur eine leere Seite, der Inhalt
 *    wird nie ausgegeben.
 */
final class ConfigStore
{
    public const DEFAULT_ENDPOINT = 'https://api.n9c.io';

    public function getFilePath(): string
    {
        return Environment::getConfigPath() . '/n9c_monitor.php';
    }

    /**
     * @return array{instance_id: ?string, secret: ?string, endpoint: string, source: string}
     */
    public function load(): array
    {
        $fromFile = [];
        $path = $this->getFilePath();
        if (is_file($path)) {
            $data = include $path;
            if (is_array($data)) {
                $fromFile = $data;
            }
        }

        $envInstance = getenv('N9C_MONITOR_INSTANCE') ?: null;
        $envSecret = getenv('N9C_MONITOR_SECRET') ?: null;
        $envEndpoint = getenv('N9C_MONITOR_ENDPOINT') ?: null;

        return [
            'instance_id' => $envInstance ?? ($fromFile['instance_id'] ?? null),
            'secret' => $envSecret ?? ($fromFile['secret'] ?? null),
            'endpoint' => rtrim($envEndpoint ?? ($fromFile['endpoint'] ?? self::DEFAULT_ENDPOINT), '/'),
            'source' => $envInstance !== null ? 'env' : ($fromFile !== [] ? 'file' : 'none'),
        ];
    }

    public function isConnected(): bool
    {
        $config = $this->load();
        return !empty($config['instance_id']) && !empty($config['secret']);
    }

    public function save(string $instanceId, string $secret, string $endpoint): void
    {
        $content = "<?php\n"
            . "// N9C Inside Monitor - Zugangsdaten dieser Instanz. Nicht weitergeben,\n"
            . "// nicht ins Git-Repository einchecken.\n"
            . "// Erzeugt am " . gmdate('Y-m-d H:i:s') . " UTC durch n9c:monitor:connect\n"
            . 'return ' . var_export([
                'instance_id' => $instanceId,
                'secret' => $secret,
                'endpoint' => rtrim($endpoint, '/'),
            ], true) . ";\n";

        $path = $this->getFilePath();
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Konnte ' . $path . ' nicht schreiben (Schreibrechte pruefen)', 1790265000);
        }
        @chmod($path, 0600);
    }
}
