<?php
/**
 * Space members: list, change role, remove.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;
use App\Services\Push;

class MembersController extends Controller
{
    /** GET /v1/spaces/{id}/members */
    public function index(array $params): void
    {
        [$space] = $this->requireMember($params['id']);
        $st = $this->db->prepare('SELECT m.user_id, m.role, m.joined_at, u.name, u.avatar_color, u.email, u.status
                                  FROM space_members m JOIN users u ON u.id = m.user_id
                                  WHERE m.space_id = ? ORDER BY m.joined_at ASC');
        $st->execute([$space['id']]);
        $members = array_map(function (array $r): array {
            $r['joined_at'] = (int) $r['joined_at'];
            return $r;
        }, $st->fetchAll());
        json(['members' => $members, 'owner_id' => $space['owner_id']]);
    }

    /** PATCH /v1/spaces/{id}/members/{uid} {role} (owner). role=owner transfers ownership. */
    public function update(array $params): void
    {
        [$space] = $this->requireMember($params['id'], 'owner');
        $u = auth();
        $target = $params['uid'];
        $role = require_enum(input()['role'] ?? null, array_keys(self::ROLES), 'role');
        $tm = $this->membership($space['id'], $target);
        if (!$tm) {
            fail('not_found', 'Member not found', 404);
        }
        if ($target === $u['id'] && $role !== 'owner') {
            fail('validation', 'Transfer ownership to someone else instead of demoting yourself', 400);
        }
        $this->db->beginTransaction();
        if ($role === 'owner') {
            $this->db->prepare('UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ?')->execute(['editor', $space['id'], $u['id']]);
            $this->db->prepare('UPDATE spaces SET owner_id = ?, updated_at = ? WHERE id = ?')->execute([$target, now_ms(), $space['id']]);
        } else {
            $this->db->prepare('UPDATE spaces SET updated_at = ? WHERE id = ?')->execute([now_ms(), $space['id']]);
        }
        $this->db->prepare('UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ?')->execute([$role, $space['id'], $target]);
        Activity::log($this->db, $space['id'], $u['id'], 'role_changed', 'member', $target, ['role' => $role]);
        $this->db->commit();
        Push::toUsers([$target], null, $space['name'], 'Your role is now ' . $role, ['kind' => 'role_changed', 'space_id' => $space['id']]);
        json(['ok' => true, 'user_id' => $target, 'role' => $role]);
    }

    /** DELETE /v1/spaces/{id}/members/{uid} (owner; or yourself = leave) */
    public function remove(array $params): void
    {
        $u = auth();
        $target = $params['uid'];
        if ($target === $u['id']) {
            (new SpacesController())->leave(['id' => $params['id']]);
            return;
        }
        [$space] = $this->requireMember($params['id'], 'owner');
        $tm = $this->membership($space['id'], $target);
        if (!$tm) {
            fail('not_found', 'Member not found', 404);
        }
        $this->db->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')->execute([$space['id'], $target]);
        $this->db->prepare('UPDATE spaces SET updated_at = ? WHERE id = ?')->execute([now_ms(), $space['id']]);
        Activity::log($this->db, $space['id'], $u['id'], 'member_removed', 'member', $target);
        Push::toUsers([$target], null, $space['name'], 'You were removed from this space', ['kind' => 'member_removed', 'space_id' => $space['id']]);
        json(['ok' => true, 'removed' => $target]);
    }
}
