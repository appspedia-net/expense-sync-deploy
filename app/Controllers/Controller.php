<?php
/**
 * Base controller: space lookup + role checks shared by the space-scoped controllers.
 */

declare(strict_types=1);

namespace App\Controllers;

use PDO;

abstract class Controller
{
    public const ROLES = ['owner' => 3, 'editor' => 2, 'viewer' => 1];
    public const SPACE_TYPES = ['family', 'trip', 'flatmates', 'office', 'project', 'other'];
    public const SHARE_MODES = ['tagged', 'all'];

    protected PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    /** Space row or 404. */
    protected function space(string $id): array
    {
        $st = $this->db->prepare('SELECT * FROM spaces WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $space = $st->fetch();
        if (!$space) {
            fail('not_found', 'Space not found', 404);
        }
        return $space;
    }

    /** Membership row (role) for a user in a space, or null. */
    protected function membership(string $spaceId, string $userId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM space_members WHERE space_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$spaceId, $userId]);
        $m = $st->fetch();
        return $m ?: null;
    }

    /**
     * Require that the current user is a member with at least $minRole.
     * Returns [space, membership].
     */
    protected function requireMember(string $spaceId, string $minRole = 'viewer', bool $allowArchived = true): array
    {
        $user = auth();
        $space = $this->space($spaceId);
        $m = $this->membership($spaceId, $user['id']);
        if (!$m) {
            fail('forbidden', 'You are not a member of this space', 403);
        }
        if (self::ROLES[$m['role']] < self::ROLES[$minRole]) {
            fail('forbidden', "This action requires the {$minRole} role", 403);
        }
        if (!$allowArchived && (int) $space['archived'] === 1) {
            fail('space_archived', 'This space is archived', 409);
        }
        return [$space, $m];
    }

    /** Space row shaped for API output, with the caller's role/extra info merged in. */
    protected function spaceOut(array $space, array $extra = []): array
    {
        return array_merge([
            'id' => $space['id'],
            'name' => $space['name'],
            'type' => $space['type'],
            'currency' => $space['currency'],
            'icon' => $space['icon'],
            'color' => $space['color'],
            'share_mode' => $space['share_mode'],
            'owner_id' => $space['owner_id'],
            'archived' => (int) $space['archived'] === 1,
            'created_at' => (int) $space['created_at'],
            'updated_at' => (int) $space['updated_at'],
        ], $extra);
    }

    /** Number of members in a space. */
    protected function memberCount(string $spaceId): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) AS c FROM space_members WHERE space_id = ?');
        $st->execute([$spaceId]);
        return (int) $st->fetch()['c'];
    }
}
