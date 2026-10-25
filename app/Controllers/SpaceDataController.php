<?php
/**
 * Read-only space views: balances and activity feed.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;
use App\Services\Balances;

class SpaceDataController extends Controller
{
    /** GET /v1/spaces/{id}/balances */
    public function balances(array $params): void
    {
        [$space] = $this->requireMember($params['id']);
        $res = Balances::compute($this->db, $space);
        $res['space_id'] = $space['id'];
        $res['computed_at'] = now_ms();
        json($res);
    }

    /** GET /v1/spaces/{id}/activity?since={ms}&limit=100 */
    public function activity(array $params): void
    {
        [$space] = $this->requireMember($params['id']);
        $since = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        $res = Activity::feed($this->db, $space['id'], $since, $limit);
        $res['space_id'] = $space['id'];
        json($res);
    }
}
