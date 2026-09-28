<?php

declare(strict_types=1);

namespace N9c\Monitor\Service;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * HTTP-Client fuer die Agent-API (inside-monitor/PROTOCOL.md).
 * Nutzt TYPO3s RequestFactory, damit HTTP-Proxy-Einstellungen aus
 * $GLOBALS['TYPO3_CONF_VARS']['HTTP'] greifen.
 */
final class Client
{
    public function __construct(private readonly RequestFactory $requestFactory)
    {
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Nachweis beim Neuverbinden (siehe inside-monitor/PROTOCOL.md).
     */
    public static function rebindProof(string $previousSecret, string $token): string
    {
        return 'sha256=' . hash_hmac('sha256', 'rebind.' . trim($token), $previousSecret);
    }

    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    public function enroll(string $endpoint, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->post($endpoint . '/agent/v1/enroll', $body, ['Content-Type' => 'application/json']);
    }

    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    public function sendReport(string $endpoint, string $instanceId, string $secret, string $body): array
    {
        $timestamp = (string)time();
        return $this->post($endpoint . '/agent/v1/report', $body, [
            'Content-Type' => 'application/json',
            'X-N9C-Instance' => $instanceId,
            'X-N9C-Timestamp' => $timestamp,
            'X-N9C-Signature' => self::sign($secret, $timestamp, $body),
        ]);
    }

    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    private function post(string $url, string $body, array $headers): array
    {
        $headers['User-Agent'] = 'n9c_monitor/' . Collector::AGENT_VERSION;
        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
            'http_errors' => false,
        ]);
        $raw = (string)$response->getBody();
        $data = json_decode($raw, true);
        return [
            'status' => $response->getStatusCode(),
            'data' => is_array($data) ? $data : ['detail' => mb_substr($raw, 0, 500)],
        ];
    }
}
