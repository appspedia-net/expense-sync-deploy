<?php
/**
 * GET /v1/health -> {ok, time, db, version}
 */

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

class HealthController
{
    public function health(array $params): void
    {
        $dbOk = false;
        try {
            $pdo = db_try();
            if ($pdo) {
                $pdo->query('SELECT 1')->fetch();
                $dbOk = true;
            }
        } catch (Throwable $e) {
            $dbOk = false;
        }
        json([
            'ok' => $dbOk,
            'time' => now(),
            'time_ms' => now_ms(),
            'db' => $dbOk,
            'version' => '3.0.0',
            'php' => PHP_VERSION,
        ], $dbOk ? 200 : 503);
    }
}
