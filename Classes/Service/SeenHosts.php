<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Merkt sich, unter welchen Domains diese Installation tatsaechlich
 * aufgerufen wird - fuer Sites mit Einstiegspunkt ohne Domain (z. B. "/"),
 * bei denen TYPO3 unter jeder Domain antwortet und die Konfiguration keine
 * Domain nennt. Gespeichert werden nur Schema + Host (+ Port), hoechstens
 * MAX_ORIGINS, die zuletzt gesehenen zuerst - keine Pfade, keine Besucherdaten.
 *
 * Aufgerufen nur, wenn ohnehin ein automatischer Report faellig ist (siehe
 * AutoReportMiddleware) - normale Seitenaufrufe kostet das nichts.
 */
final class SeenHosts
{
    private const MAX_ORIGINS = 5;
    private const FILE = 'seen_hosts.json';

    public function file(): string
    {
        return Environment::getVarPath() . '/n9c_monitor/' . self::FILE;
    }

    /**
     * @return list<string> z. B. ["https://www.kunde.de"]
     */
    public function all(): array
    {
        $raw = @file_get_contents($this->file());
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, fn ($o) => is_string($o) && self::normalize($o) === $o));
    }

    public function remember(string $origin): void
    {
        $origin = self::normalize($origin);
        if ($origin === null) {
            return;
        }
        $current = $this->all();
        if (($current[0] ?? null) === $origin) {
            return; // unveraendert - nichts schreiben
        }
        $list = array_slice(array_values(array_unique(array_merge([$origin], $current))), 0, self::MAX_ORIGINS);
        $dir = dirname($this->file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file(), json_encode($list, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * "https://Www.Kunde.de:443/pfad" -> "https://www.kunde.de"; ungueltig -> null.
     */
    public static function normalize(string $origin): ?string
    {
        $parts = parse_url(trim($origin));
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9.-]{1,253}$/', $host) || !str_contains($host, '.')) {
            return null;
        }
        $scheme = $parts['scheme'];
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');
    }
}
