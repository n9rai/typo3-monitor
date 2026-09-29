<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Registry;

/**
 * Gemeinsamer Kern fuer "Report bauen + senden + Ergebnis merken" -
 * genutzt vom CLI-Befehl n9c:monitor:report (auch als Scheduler-Task) und
 * vom automatischen Report bei Seitenaufrufen (AutoReport).
 */
final class ReportService
{
    public const REGISTRY_NAMESPACE = 'tx_n9cmonitor';

    public function __construct(
        private readonly Client $client,
        private readonly Collector $collector,
        private readonly ConfigStore $configStore,
        private readonly Registry $registry,
        private readonly ReportStamp $stamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(bool $quick = false): array
    {
        return $this->collector->collect($quick);
    }

    /**
     * @param string $trigger cli | auto
     * @return array{status: int, data: array<string, mixed>}
     * @throws NotConnectedException
     */
    public function send(string $trigger = 'cli', bool $quick = false): array
    {
        $config = $this->configStore->load();
        if (empty($config['instance_id']) || empty($config['secret'])) {
            throw new NotConnectedException('Noch nicht verbunden. Zuerst: n9c:monitor:connect <code>', 1790270001);
        }
        $report = $this->buildReport($quick);
        $report['cms_specific']['trigger'] = $trigger;
        $body = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $result = $this->client->sendReport($config['endpoint'], $config['instance_id'], $config['secret'], $body);
        } catch (\Throwable $e) {
            $this->remember(0, ['detail' => $e->getMessage()], $trigger);
            throw $e;
        }

        $this->remember($result['status'], $result['data'], $trigger);
        // 200 = gesendet; 409/429 = Backend hat kuerzlich schon einen Report -> ebenfalls "erledigt"
        if (in_array($result['status'], [200, 409, 429], true)) {
            $this->stamp->markSent();
        }
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastResult(): ?array
    {
        $last = $this->registry->get(self::REGISTRY_NAMESPACE, 'lastResult');
        return is_array($last) ? $last : null;
    }

    private function remember(int $status, array $data, string $trigger): void
    {
        $detail = $data['detail'] ?? null;
        $this->registry->set(self::REGISTRY_NAMESPACE, 'lastResult', [
            'time' => time(),
            'trigger' => $trigger,
            'status' => $status,
            'score' => $data['score'] ?? null,
            'score_status' => $data['score_status'] ?? null,
            'counts' => $data['counts'] ?? null,
            'delta' => $data['delta'] ?? null,
            'detail' => $detail === null ? null : mb_substr(is_string($detail) ? $detail : (string)json_encode($detail), 0, 500),
            // Offene Befunde in Klartext (Backend liefert sie ab API-Stand 24.09.2026 mit)
            'findings' => is_array($data['findings'] ?? null) ? array_slice($data['findings'], 0, 50) : null,
            // Tarif des N9C-Kontos (Backend ab 29.09.2026): tier, label, until, full
            'plan' => is_array($data['plan'] ?? null) ? array_intersect_key($data['plan'], array_flip(['tier', 'label', 'until', 'full'])) : null,
        ]);
    }
}
