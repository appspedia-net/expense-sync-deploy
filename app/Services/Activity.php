<?php
/**
 * Activity feed writer: one row per noteworthy event in a space.
 *
 * kinds: transaction_added | transaction_edited | transaction_deleted |
 *        settlement_added | member_joined | member_left | member_removed |
 *        role_changed | space_created | space_updated | space_archived | comment_added
 */

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

class Activity
{
    public static function log(PDO $pdo, string $spaceId, ?string $userId, string $kind, ?string $entity = null, ?string $entityId = null, array $data = []): void
    {
        try {
            $st = $pdo->prepare('INSERT INTO activity (space_id, user_id, kind, entity, entity_id, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$spaceId, $userId, $kind, $entity, $entityId, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, now_ms()]);
        } catch (Throwable $e) {
            log_msg('error', 'activity log failed: ' . $e->getMessage());
        }
    }

    /** Feed items after $since (ms), oldest first. */
    public static function feed(PDO $pdo, string $spaceId, int $since, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $st = $pdo->prepare('SELECT a.id, a.space_id, a.user_id, u.name AS user_name, u.avatar_color, a.kind, a.entity, a.entity_id, a.data, a.created_at
                             FROM activity a LEFT JOIN users u ON u.id = a.user_id
                             WHERE a.space_id = ? AND a.created_at > ?
                             ORDER BY a.created_at ASC, a.id ASC LIMIT ' . ($limit + 1));
        $st->execute([$spaceId, $since]);
        $rows = $st->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['created_at'] = (int) $r['created_at'];
            $r['data'] = json_col($r['data']);
        }
        unset($r);
        return [
            'items' => $rows,
            'next_since' => $rows ? (int) end($rows)['created_at'] : $since,
            'has_more' => $hasMore,
        ];
    }
}
