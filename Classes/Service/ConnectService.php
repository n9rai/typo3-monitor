<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

/**
 * Verbinden per Einmal-Code - gemeinsam genutzt von n9c:monitor:connect und
 * dem Backend-Modul. Ist die Instanz schon verbunden, wird mit dem bisherigen
 * Secret nachgewiesen, wer wir sind: das Backend behaelt dann Instanz und
 * Verlauf und stellt nur ein neues Secret aus.
 */
final class ConnectService
{
    public function __construct(
        private readonly Client $client,
        private readonly Collector $collector,
        private readonly ConfigStore $configStore,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, instance_id?: string, reused?: bool}
     */
    public function connect(string $code, string $endpoint, bool $force): array
    {
        $code = trim($code);
        if (!preg_match('/^n9c-enroll-[0-9a-f]{32}$/', $code)) {
            return ['ok' => false, 'message' => 'Der Verbindungscode hat nicht das erwartete Format (n9c-enroll-... mit 32 Zeichen).'];
        }
        $config = $this->configStore->load();
        if ($config['source'] === 'env') {
            return ['ok' => false, 'message' => 'Die Verbindung ist über Umgebungsvariablen (N9C_MONITOR_*) festgelegt. Bitte dort ändern.'];
        }
        $connected = !empty($config['instance_id']) && !empty($config['secret']);
        if ($connected && !$force) {
            return ['ok' => false, 'message' => 'Diese Instanz ist bereits verbunden. Zum Neuverbinden „Neu verbinden“ verwenden bzw. --force angeben.'];
        }

        $report = $this->collector->collect(true);
        $endpoint = rtrim($endpoint !== '' ? $endpoint : $config['endpoint'], '/');
        $payload = [
            'token' => $code,
            'agent' => $report['agent'],
            'cms' => ['type' => 'typo3', 'version' => $report['cms']['version']],
            'sites' => $report['sites'],
        ];
        if ($connected) {
            $payload['previous_instance_id'] = $config['instance_id'];
            $payload['previous_proof'] = Client::rebindProof($config['secret'], $code);
        }

        try {
            $result = $this->client->enroll($endpoint, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verbindung zu ' . $endpoint . ' fehlgeschlagen: ' . $e->getMessage()];
        }
        if ($result['status'] !== 200 || empty($result['data']['instance_id']) || empty($result['data']['secret'])) {
            $detail = $result['data']['detail'] ?? $result['data'];
            return ['ok' => false, 'message' => 'Verbinden abgelehnt (HTTP ' . $result['status'] . '): '
                . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))];
        }

        try {
            $this->configStore->save($result['data']['instance_id'], $result['data']['secret'], $endpoint);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() . ' - alternativ Umgebungsvariablen N9C_MONITOR_INSTANCE/N9C_MONITOR_SECRET setzen.'];
        }

        $reused = !empty($result['data']['reused']);
        return [
            'ok' => true,
            'instance_id' => $result['data']['instance_id'],
            'reused' => $reused,
            'message' => ($reused ? 'Neu verbunden (bestehende Instanz, Verlauf bleibt erhalten). ' : 'Verbunden. ')
                . 'Instanz-ID: ' . $result['data']['instance_id'],
        ];
    }
}
