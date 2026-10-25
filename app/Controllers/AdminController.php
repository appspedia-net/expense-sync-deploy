<?php
/**
 * GET /v1/admin/stats  (header X-Admin-Token)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;

class AdminController extends Controller
{
    public function stats(array $params): void
    {
        Auth::requireAdmin();
        $count = function (string $sql, array $args = []): int {
            $st = $this->db->prepare($sql);
            $st->execute($args);
            return (int) $st->fetchColumn();
        };
        $today = gmdate('Y-m-d');
        $dayAgo = gmdate('Y-m-d H:i:s', time() - 86400);
        $weekAgoMs = (time() - 7 * 86400) * 1000;

        $st = $this->db->prepare('SELECT kind, SUM(count) AS c, SUM(tokens) AS t FROM ai_usage WHERE day = ? GROUP BY kind');
        $st->execute([$today]);
        $ai = [];
        foreach ($st->fetchAll() as $r) {
            $ai[$r['kind']] = ['calls' => (int) $r['c'], 'tokens' => (int) $r['t']];
        }

        json([
            'time' => now(),
            'users' => [
                'total' => $count('SELECT COUNT(*) FROM users WHERE status = ?', ['active']),
                'deleted' => $count('SELECT COUNT(*) FROM users WHERE status = ?', ['deleted']),
                'new_24h' => $count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$dayAgo]),
                'active_24h' => $count('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [$dayAgo]),
            ],
            'devices' => [
                'total' => $count('SELECT COUNT(*) FROM devices'),
                'with_push' => $count('SELECT COUNT(*) FROM devices WHERE push_token IS NOT NULL'),
            ],
            'spaces' => [
                'total' => $count('SELECT COUNT(*) FROM spaces'),
                'archived' => $count('SELECT COUNT(*) FROM spaces WHERE archived = 1'),
                'active_7d' => $count('SELECT COUNT(*) FROM spaces WHERE updated_at >= ?', [$weekAgoMs]),
                'memberships' => $count('SELECT COUNT(*) FROM space_members'),
            ],
            'rows' => [
                'transactions' => $count('SELECT COUNT(*) FROM sp_transactions WHERE deleted = 0'),
                'settlements' => $count('SELECT COUNT(*) FROM sp_settlements WHERE deleted = 0'),
                'comments' => $count('SELECT COUNT(*) FROM sp_comments WHERE deleted = 0'),
                'activity' => $count('SELECT COUNT(*) FROM activity'),
            ],
            'invites_active' => $count('SELECT COUNT(*) FROM invites WHERE (expires_at IS NULL OR expires_at > ?) AND (max_uses IS NULL OR uses < max_uses)', [now()]),
            'otp_24h' => $count('SELECT COUNT(*) FROM otp_codes WHERE created_at >= ?', [$dayAgo]),
            'ai_today' => $ai,
            'php' => PHP_VERSION,
        ]);
    }
}
