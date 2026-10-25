<?php
/**
 * GET /v1/admin/stats  (header X-Admin-Token)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;

class AdminController extends Controller
{
    /**
     * POST /v1/admin/migrate — (re)applies schema.sql (idempotent CREATE TABLE IF NOT EXISTS).
     */
    public function migrate(array $params): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            // Tiny form so the migration can be run from a browser without custom headers.
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;padding:32px"><h3>Run schema migration</h3>'
               . '<form method="post"><input name="admin_token" type="password" placeholder="admin token" style="width:360px"> '
               . '<button>Run</button></form></body>';
            exit;
        }
        Auth::requireAdmin();
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/schema.sql');
        // strip full-line comments, then split on ';'
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $n = 0; $errors = [];
        foreach ($stmts as $st) {
            try { $this->db->exec($st); $n++; }
            catch (\Throwable $e) { $errors[] = substr($st, 0, 60) . ' => ' . $e->getMessage(); }
        }
        $tables = $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        json(['applied' => $n, 'errors' => $errors, 'tables' => $tables]);
    }

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
