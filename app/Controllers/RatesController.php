<?php
/**
 * GET /v1/rates?base=USD  -> {base, rates:{...}, fetched_at, stale}
 * Public (X-App-Key only), cached 24h in fx_rates.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Fx;

class RatesController extends Controller
{
    public function index(array $params): void
    {
        $base = isset($_GET['base']) ? (string) $_GET['base'] : 'USD';
        $res = Fx::rates($this->db, $base);
        if ($res === null) {
            fail('rates_unavailable', 'Rates are not available right now (or unknown base currency)', 503);
        }
        json($res);
    }
}
