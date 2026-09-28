<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Zeitstempel "letzter Report" als Datei-mtime unter var/n9c_monitor/.
 * Bewusst eine Datei statt Registry/DB: der automatische Report prueft bei
 * JEDEM Seitenaufruf, ob er faellig ist - ein filemtime() kostet praktisch
 * nichts, eine DB-Abfrage pro Aufruf schon.
 */
final class ReportStamp
{
    private const RETRY_AFTER_FAILURE_SECONDS = 1800;

    public function directory(): string
    {
        return Environment::getVarPath() . '/n9c_monitor';
    }

    public function file(): string
    {
        return $this->directory() . '/last_report';
    }

    public function lastSentAt(): ?int
    {
        clearstatcache(true, $this->file());
        $mtime = @filemtime($this->file());
        return $mtime === false ? null : $mtime;
    }

    public function isDue(int $intervalSeconds): bool
    {
        $last = $this->lastSentAt();
        return $last === null || (time() - $last) >= $intervalSeconds;
    }

    public function markSent(): void
    {
        $this->touchAt(time());
    }

    /**
     * Nach einem Fehlschlag nicht bei jedem Seitenaufruf neu versuchen,
     * sondern erst nach RETRY_AFTER_FAILURE_SECONDS.
     */
    public function markAttempt(int $intervalSeconds): void
    {
        $this->touchAt(time() - $intervalSeconds + self::RETRY_AFTER_FAILURE_SECONDS);
    }

    /**
     * Nicht-blockierende Sperre - bei parallelen Seitenaufrufen sendet nur einer.
     * @return resource|null Handle zum spaeteren Freigeben, null = jemand anders sendet gerade
     */
    public function tryLock()
    {
        if (!$this->ensureDirectory()) {
            return null;
        }
        $handle = @fopen($this->directory() . '/lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function touchAt(int $time): void
    {
        if ($this->ensureDirectory()) {
            @touch($this->file(), $time);
        }
    }

    private function ensureDirectory(): bool
    {
        $dir = $this->directory();
        return is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir);
    }
}
