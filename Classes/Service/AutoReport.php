<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Automatischer Report ohne Cronjob ("Pseudo-Cron", vgl. WP-Cron):
 * Bei normalen Seitenaufrufen wird geprueft, ob der letzte Report aelter als
 * das Intervall ist; wenn ja, wird NACH Auslieferung der Seite gesendet.
 * Fuer Hosting-Pakete ohne Cron (bei vielen Shared-Hostern nicht verfuegbar).
 *
 * Laeuft zusaetzlich ein Cron/Scheduler-Task, stoert das nicht: jeder
 * Report setzt den Zeitstempel zurueck. Abschaltbar in den Extension-
 * Einstellungen (autoReport = 0).
 */
final class AutoReport
{
    private const DEFAULT_INTERVAL_HOURS = 6;
    private const WEB_TIME_LIMIT_SECONDS = 120;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly ReportStamp $stamp,
        private readonly ConfigStore $configStore,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->setting('autoReport', true);
    }

    public function intervalSeconds(): int
    {
        $hours = (int)$this->setting('autoReportIntervalHours', self::DEFAULT_INTERVAL_HOURS);
        return max(1, $hours) * 3600;
    }

    /**
     * Guenstige Pruefung fuer jeden Seitenaufruf: nur Datei-mtime + Config-Datei.
     */
    public function isDue(): bool
    {
        return $this->isEnabled()
            && $this->stamp->isDue($this->intervalSeconds())
            && $this->configStore->isConnected();
    }

    /**
     * Wird per register_shutdown_function() aufgerufen - also nachdem TYPO3
     * die Antwort bereits ausgegeben hat.
     */
    public function runAfterResponse(): void
    {
        // Verbindung zum Besucher schliessen, bevor gesammelt/gesendet wird
        // (php-fpm bzw. LiteSpeed). Ohne diese Funktionen (z.B. php-cgi) wartet
        // der eine ausloesende Aufruf, bis der Report durch ist - einmal pro Intervall.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(self::WEB_TIME_LIMIT_SECONDS);

        $lock = $this->stamp->tryLock();
        if ($lock === null) {
            return; // ein paralleler Aufruf sendet bereits
        }
        try {
            if (!$this->stamp->isDue($this->intervalSeconds())) {
                return; // inzwischen von jemand anderem erledigt
            }
            // Vorab als "versucht" markieren: scheitert der Versuch hart
            // (Fatal Error, Timeout), wird erst nach 30 Minuten erneut probiert.
            $this->stamp->markAttempt($this->intervalSeconds());
            // quick = zeitlich begrenzter Datei-Scan, damit ein ausloesender
            // Seitenaufruf ohne fastcgi_finish_request nicht lange haengt
            $this->reportService->send('auto', !function_exists('fastcgi_finish_request'));
        } catch (\Throwable) {
            // Fehler stehen via ReportService im letzten Ergebnis (n9c:monitor:status);
            // nie etwas an den Besucher durchreichen.
        } finally {
            $this->stamp->unlock($lock);
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            $value = $this->extensionConfiguration->get('n9c_monitor', $key);
            return $value === '' || $value === null ? $default : $value;
        } catch (\Throwable) {
            // Extension-Konfiguration noch nicht geschrieben (z.B. nach Update per ZIP)
            return $default;
        }
    }
}
