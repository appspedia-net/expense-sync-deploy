<?php
/**
 * Tiny curl wrapper used by the Apple/Google/FX/OpenAI/APNs integrations.
 */

declare(strict_types=1);

namespace App\Services;

class Http
{
    /**
     * Perform an HTTP request.
     *
     * @param string      $method  GET|POST|...
     * @param string      $url
     * @param string|null $body    raw body (already encoded)
     * @param string[]    $headers "Name: value" strings
     * @param int         $timeout seconds
     * @return array{status:int, body:string, error:string|null, headers:array<string,string>}
     */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: ExpenseManagerSync/3.0 (+https://imrashid.net)'], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $err = curl_error($ch) ?: null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'status' => $status,
            'body' => is_string($out) ? $out : '',
            'error' => $err,
            'headers' => $respHeaders,
        ];
    }

    public static function get(string $url, array $headers = [], int $timeout = 15): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** POST a JSON body; returns the decoded JSON (or null) alongside the raw response. */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 30): array
    {
        $res = self::request('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), array_merge(['Content-Type: application/json'], $headers), $timeout);
        $res['json'] = json_decode($res['body'], true);
        return $res;
    }
}
