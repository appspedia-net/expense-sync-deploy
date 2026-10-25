<?php
/**
 * Daily FX rates from https://open.er-api.com/v6/latest/USD, cached in the
 * fx_rates table for 24h. Other bases are derived from the USD table so we
 * only ever make one upstream call a day.
 */

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

class Fx
{
    private const SOURCE = 'https://open.er-api.com/v6/latest/USD';

    /**
     * @return array{base:string, rates:array<string,float>, fetched_at:string, stale:bool}|null
     */
    public static function rates(PDO $pdo, string $base = 'USD'): ?array
    {
        $base = strtoupper(preg_replace('/[^A-Za-z]/', '', $base) ?: 'USD');
        $usd = self::usdTable($pdo);
        if ($usd === null) {
            return null;
        }
        $rates = $usd['rates'];
        if ($base !== 'USD') {
            if (empty($rates[$base])) {
                return null;
            }
            $div = (float) $rates[$base];
            $conv = [];
            foreach ($rates as $code => $r) {
                $conv[$code] = round(((float) $r) / $div, 6);
            }
            $rates = $conv;
        }
        ksort($rates);
        return [
            'base' => $base,
            'rates' => $rates,
            'fetched_at' => $usd['fetched_at'],
            'stale' => $usd['stale'],
        ];
    }

    /** USD rate table from cache (refreshing when older than fx.cache_seconds). */
    private static function usdTable(PDO $pdo): ?array
    {
        $ttl = (int) config('fx.cache_seconds', 86400);
        $row = null;
        try {
            $st = $pdo->prepare('SELECT base, rates, fetched_at FROM fx_rates WHERE base = ?');
            $st->execute(['USD']);
            $row = $st->fetch() ?: null;
        } catch (Throwable $e) {
            log_msg('error', 'fx cache read: ' . $e->getMessage());
        }

        $fresh = $row && (time() - strtotime($row['fetched_at'] . ' UTC')) < $ttl;
        if ($fresh) {
            return ['rates' => json_col($row['rates']), 'fetched_at' => $row['fetched_at'], 'stale' => false];
        }

        $res = Http::get(self::SOURCE, ['Accept: application/json'], 10);
        $j = json_decode($res['body'], true);
        if ($res['status'] === 200 && is_array($j) && ($j['result'] ?? '') === 'success' && !empty($j['rates'])) {
            $rates = [];
            foreach ($j['rates'] as $code => $r) {
                $rates[strtoupper((string) $code)] = (float) $r;
            }
            $json = json_encode($rates);
            try {
                if ($row) {
                    $pdo->prepare('UPDATE fx_rates SET rates = ?, fetched_at = ? WHERE base = ?')->execute([$json, now(), 'USD']);
                } else {
                    $pdo->prepare('INSERT INTO fx_rates (base, rates, fetched_at) VALUES (?, ?, ?)')->execute(['USD', $json, now()]);
                }
            } catch (Throwable $e) {
                log_msg('error', 'fx cache write: ' . $e->getMessage());
            }
            return ['rates' => $rates, 'fetched_at' => now(), 'stale' => false];
        }

        log_msg('error', 'FX fetch failed: HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''));
        if ($row) {
            return ['rates' => json_col($row['rates']), 'fetched_at' => $row['fetched_at'], 'stale' => true];
        }
        return null;
    }
}
