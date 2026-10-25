<?php
/**
 * Spaces: create / list / show / update / delete / leave / archive.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;
use App\Services\Balances;
use App\Services\Push;

class SpacesController extends Controller
{
    /** POST /v1/spaces {name, type, currency, icon, color, share_mode, id?} */
    public function create(array $params): void
    {
        $u = auth();
        $in = input();
        $name = str_input($in, 'name', 80, true);
        $type = require_enum($in['type'] ?? 'other', self::SPACE_TYPES, 'type');
        $shareMode = require_enum($in['share_mode'] ?? 'tagged', self::SHARE_MODES, 'share_mode');
        $currency = strtoupper((string) str_input($in, 'currency', 3, false, $u['currency'] ?: 'USD'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            fail('validation', 'currency must be a 3-letter ISO code', 400, ['field' => 'currency']);
        }
        $icon = str_input($in, 'icon', 64);
        $color = str_input($in, 'color', 16);

        // Free-tier limits are enforced in the app; the server only caps abuse.
        $st = $this->db->prepare('SELECT COUNT(*) AS c FROM spaces WHERE owner_id = ?');
        $st->execute([$u['id']]);
        if ((int) $st->fetch()['c'] >= (int) config('limits.max_spaces_per_user', 100)) {
            fail('limit_reached', 'Too many spaces', 409);
        }

        // Accept a client-generated uuid so the app can create offline and sync later.
        $id = isset($in['id']) && is_string($in['id']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $in['id']) ? $in['id'] : uuid();
        $st = $this->db->prepare('SELECT id FROM spaces WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetch()) {
            fail('conflict', 'A space with this id already exists', 409);
        }

        $ts = now_ms();
        $this->db->beginTransaction();
        $this->db->prepare('INSERT INTO spaces (id, name, type, currency, icon, color, share_mode, owner_id, archived, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
            ->execute([$id, $name, $type, $currency, $icon, $color, $shareMode, $u['id'], $ts, $ts]);
        $this->db->prepare('INSERT INTO space_members (space_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $u['id'], 'owner', $ts]);
        Activity::log($this->db, $id, $u['id'], 'space_created', null, null, ['name' => $name]);
        $this->db->commit();

        json(['space' => $this->spaceOut($this->space($id), ['my_role' => 'owner', 'member_count' => 1, 'my_balance' => 0.0])], 201);
    }

    /** GET /v1/spaces?archived=0|1|all -> list with my role, member count, my balance */
    public function index(array $params): void
    {
        $u = auth();
        $archived = $_GET['archived'] ?? '0';
        $where = $archived === 'all' ? '' : ' AND s.archived = ' . ($archived === '1' ? '1' : '0');
        $st = $this->db->prepare("SELECT s.*, m.role AS my_role, m.joined_at,
                                        (SELECT COUNT(*) FROM space_members mm WHERE mm.space_id = s.id) AS member_count
                                   FROM spaces s JOIN space_members m ON m.space_id = s.id
                                  WHERE m.user_id = ?{$where}
                               ORDER BY s.archived ASC, s.updated_at DESC");
        $st->execute([$u['id']]);
        $out = [];
        foreach ($st->fetchAll() as $s) {
            $balance = Balances::compute($this->db, $s);
            $mine = 0.0;
            foreach ($balance['members'] as $m) {
                if ($m['user_id'] === $u['id']) {
                    $mine = $m['net'];
                }
            }
            $out[] = $this->spaceOut($s, [
                'my_role' => $s['my_role'],
                'member_count' => (int) $s['member_count'],
                'my_balance' => $mine,
                'total_expense' => $balance['total_expense'],
                'joined_at' => (int) $s['joined_at'],
            ]);
        }
        json(['spaces' => $out]);
    }

    /** GET /v1/spaces/{id} */
    public function show(array $params): void
    {
        [$space, $m] = $this->requireMember($params['id']);
        $st = $this->db->prepare('SELECT m.user_id, m.role, m.joined_at, u.name, u.avatar_color, u.email FROM space_members m JOIN users u ON u.id = m.user_id WHERE m.space_id = ? ORDER BY m.joined_at ASC');
        $st->execute([$space['id']]);
        $members = array_map(function (array $r): array {
            $r['joined_at'] = (int) $r['joined_at'];
            return $r;
        }, $st->fetchAll());
        json(['space' => $this->spaceOut($space, ['my_role' => $m['role'], 'member_count' => count($members), 'members' => $members])]);
    }

    /** PATCH /v1/spaces/{id} {name, type, currency, icon, color, share_mode} (editor+) */
    public function update(array $params): void
    {
        [$space, $m] = $this->requireMember($params['id'], 'editor', false);
        $u = auth();
        $in = input();
        $sets = [];
        $vals = [];
        $changed = [];
        if (array_key_exists('name', $in)) {
            $sets[] = 'name = ?';
            $vals[] = $changed['name'] = str_input($in, 'name', 80, true);
        }
        if (array_key_exists('type', $in)) {
            $sets[] = 'type = ?';
            $vals[] = $changed['type'] = require_enum($in['type'], self::SPACE_TYPES, 'type');
        }
        if (array_key_exists('share_mode', $in)) {
            $sets[] = 'share_mode = ?';
            $vals[] = $changed['share_mode'] = require_enum($in['share_mode'], self::SHARE_MODES, 'share_mode');
        }
        if (array_key_exists('currency', $in)) {
            if ($m['role'] !== 'owner') {
                fail('forbidden', 'Only the owner can change the space currency', 403);
            }
            $cur = strtoupper((string) str_input($in, 'currency', 3, true));
            if (!preg_match('/^[A-Z]{3}$/', $cur)) {
                fail('validation', 'currency must be a 3-letter ISO code', 400, ['field' => 'currency']);
            }
            $sets[] = 'currency = ?';
            $vals[] = $changed['currency'] = $cur;
        }
        if (array_key_exists('icon', $in)) {
            $sets[] = 'icon = ?';
            $vals[] = $changed['icon'] = str_input($in, 'icon', 64);
        }
        if (array_key_exists('color', $in)) {
            $sets[] = 'color = ?';
            $vals[] = $changed['color'] = str_input($in, 'color', 16);
        }
        if (!$sets) {
            fail('validation', 'Nothing to update', 400);
        }
        $sets[] = 'updated_at = ?';
        $vals[] = now_ms();
        $vals[] = $space['id'];
        $this->db->prepare('UPDATE spaces SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        Activity::log($this->db, $space['id'], $u['id'], 'space_updated', null, null, $changed);

        json(['space' => $this->spaceOut($this->space($space['id']), ['my_role' => $m['role'], 'member_count' => $this->memberCount($space['id'])])]);
    }

    /** DELETE /v1/spaces/{id} (owner) — hard delete of the space and everything in it. */
    public function destroy(array $params): void
    {
        [$space] = $this->requireMember($params['id'], 'owner');
        $u = auth();
        $this->db->beginTransaction();
        foreach (['sp_transactions', 'sp_categories', 'sp_budgets', 'sp_settlements', 'sp_comments', 'sp_recurring_rules', 'activity', 'invites', 'space_members'] as $t) {
            $this->db->prepare("DELETE FROM {$t} WHERE space_id = ?")->execute([$space['id']]);
        }
        $this->db->prepare('DELETE FROM spaces WHERE id = ?')->execute([$space['id']]);
        $this->db->commit();
        Push::toSpace($space['id'], $u['id'], $space['name'], 'This space was deleted by ' . $u['name'], ['kind' => 'space_deleted']);
        json(['ok' => true, 'deleted' => true]);
    }

    /** POST /v1/spaces/{id}/leave — owner must transfer ownership (or be the last member) first. */
    public function leave(array $params): void
    {
        [$space, $m] = $this->requireMember($params['id']);
        $u = auth();
        $count = $this->memberCount($space['id']);
        if ($m['role'] === 'owner' && $count > 1) {
            // Hand over to the longest-standing editor (else any member) automatically.
            $st = $this->db->prepare('SELECT user_id FROM space_members WHERE space_id = ? AND user_id <> ? ORDER BY CASE role WHEN \'editor\' THEN 0 ELSE 1 END, joined_at ASC LIMIT 1');
            $st->execute([$space['id'], $u['id']]);
            $heir = $st->fetch();
            $this->db->prepare('UPDATE spaces SET owner_id = ?, updated_at = ? WHERE id = ?')->execute([$heir['user_id'], now_ms(), $space['id']]);
            $this->db->prepare('UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ?')->execute(['owner', $space['id'], $heir['user_id']]);
            Activity::log($this->db, $space['id'], $u['id'], 'role_changed', null, $heir['user_id'], ['role' => 'owner']);
        }
        $this->db->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')->execute([$space['id'], $u['id']]);
        if ($count <= 1) {
            // last one out: remove the space entirely
            $this->db->beginTransaction();
            foreach (['sp_transactions', 'sp_categories', 'sp_budgets', 'sp_settlements', 'sp_comments', 'sp_recurring_rules', 'activity', 'invites'] as $t) {
                $this->db->prepare("DELETE FROM {$t} WHERE space_id = ?")->execute([$space['id']]);
            }
            $this->db->prepare('DELETE FROM spaces WHERE id = ?')->execute([$space['id']]);
            $this->db->commit();
            json(['ok' => true, 'left' => true, 'space_deleted' => true]);
        }
        Activity::log($this->db, $space['id'], $u['id'], 'member_left');
        $this->db->prepare('UPDATE spaces SET updated_at = ? WHERE id = ?')->execute([now_ms(), $space['id']]);
        Push::toSpace($space['id'], $u['id'], $space['name'], $u['name'] . ' left the space', ['kind' => 'member_left']);
        json(['ok' => true, 'left' => true]);
    }

    /** POST /v1/spaces/{id}/archive {archived?: bool} (owner) — toggles; default archive. */
    public function archive(array $params): void
    {
        [$space] = $this->requireMember($params['id'], 'owner');
        $u = auth();
        $in = input();
        $archived = array_key_exists('archived', $in) ? (in_array($in['archived'], [true, 1, '1', 'true'], true) ? 1 : 0) : 1;
        $this->db->prepare('UPDATE spaces SET archived = ?, updated_at = ? WHERE id = ?')->execute([$archived, now_ms(), $space['id']]);
        Activity::log($this->db, $space['id'], $u['id'], $archived ? 'space_archived' : 'space_updated', null, null, ['archived' => (bool) $archived]);
        if ($archived) {
            Push::toSpace($space['id'], $u['id'], $space['name'], 'Space archived by ' . $u['name'], ['kind' => 'space_archived']);
        }
        json(['space' => $this->spaceOut($this->space($space['id']), ['my_role' => 'owner', 'member_count' => $this->memberCount($space['id'])])]);
    }
}
