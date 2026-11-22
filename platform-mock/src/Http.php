<?php
declare(strict_types=1);

namespace PlatformMock;

final class Http
{
    /** @return array{status: int, data: array<string, mixed>} */
    public static function postJson(string $url, array $body, float $timeoutMs = 15000): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
            CURLOPT_TIMEOUT_MS => (int)$timeoutMs,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = $raw === false ? [] : (json_decode((string)$raw, true) ?: []);
        return ['status' => $status, 'data' => $data];
    }

    public static function log(string $msg, array $obj = []): void
    {
        if (array_key_exists('password', $obj)) {
            $obj['password'] = '***';
        }
        $h = fopen('php://stdout', 'wb'); // cli-server has no STDOUT constant
        if ($h !== false) {
            fwrite($h, json_encode(['ts' => date('c'), 'msg' => $msg] + $obj, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            fclose($h);
        }
    }
}
