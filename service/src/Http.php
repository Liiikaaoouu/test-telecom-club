<?php
declare(strict_types=1);

namespace DailyPass;

final class Http
{
    /**
     * JSON HTTP request via curl.
     *
     * @param array<string, mixed>|null $json
     * @return array{status: int, data: array<string, mixed>}
     * @throws \RuntimeException on transport failure (when $throw)
     */
    public static function request(string $method, string $url, ?array $json = null, float $timeoutMs = 5000, bool $throw = true): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['content-type: application/json'],
            CURLOPT_TIMEOUT_MS     => (int)$timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => (int)min(2000, $timeoutMs),
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            if ($throw) {
                throw new \RuntimeException("transport error (curl $errno): $errstr");
            }
            return ['status' => 0, 'data' => ['error' => "transport error (curl $errno): $errstr"]];
        }
        $data = json_decode((string)$body, true);
        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }

    public static function postJson(string $url, array $body, float $timeoutMs = 5000, bool $throw = true): array
    {
        return self::request('POST', $url, $body, $timeoutMs, $throw)['data'];
    }

    /** @return array{status: int, data: array<string, mixed>} */
    public static function getJson(string $url, float $timeoutMs = 5000, bool $throw = true): array
    {
        return self::request('GET', $url, null, $timeoutMs, $throw);
    }
}
