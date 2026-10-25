<?php
/**
 * Invites: 6-character codes (A-Z0-9, no confusable chars) with optional expiry and max uses.
 * Share URL: https://imrashid.net/j/{code} (landing page + Universal Link live on imrashid.net).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;
use App\Services\Push;

class InvitesController extends Controller
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    /** POST /v1/spaces/{id}/invites {role=editor, expires_h=168, max_uses=10} (editor+) */
    public function create(array $params): void
    {
        [$space, $m] = $this->requireMember($params['id'], 'editor', false);
        $u = auth();
        $in = input();
        $role = require_enum($in['role'] ?? 'editor', ['editor', 'viewer'], 'role');
        $expiresH = isset($in['expires_h']) ? (int) $in['expires_h'] : (int) config('invites.default_expires_h', 168);
        $maxUses = isset($in['max_uses']) ? (int) $in['max_uses'] : (int) config('invites.default_max_uses', 10);
        $expiresH = max(0, min($expiresH, 24 * 365));
        $maxUses = max(0, min($maxUses, 1000));

        $code = $this->freshCode();
        $this->db->prepare('INSERT INTO invites (code, space_id, inviter_id, role, expires_at, max_uses, uses, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([
                $code, $space['id'], $u['id'], $role,
                $expiresH > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresH * 3600) : null,
                $maxUses > 0 ? $maxUses : null,
                now(),
            ]);
        json([
            'code' => $code,
            'url' => rtrim((string) config('invites.base_url', 'https://imrashid.net/j/'), '/') . '/' . $code,
            'role' => $role,
            'expires_at' => $expiresH > 0 ? gmdate('c', time() + $expiresH * 3600) : null,
            'max_uses' => $maxUses > 0 ? $maxUses : null,
        ], 201);
    }

    /** GET /v1/spaces/{id}/invites — active invites (editor+) */
    public function index(array $params): void
    {
        [$space] = $this->requireMember($params['id'], 'editor');
        $st = $this->db->prepare('SELECT i.code, i.role, i.expires_at, i.max_uses, i.uses, i.created_at, i.inviter_id, u.name AS inviter_name
                                  FROM invites i LEFT JOIN users u ON u.id = i.inviter_id
                                  WHERE i.space_id = ? AND (i.expires_at IS NULL OR i.expires_at > ?) AND (i.max_uses IS NULL OR i.uses < i.max_uses)
                                  ORDER BY i.created_at DESC');
        $st->execute([$space['id'], now()]);
        json(['invites' => $st->fetchAll()]);
    }

    /** DELETE /v1/spaces/{id}/invites/{code} — revoke (editor+) */
    public function revoke(array $params): void
    {
        [$space] = $this->requireMember($params['id'], 'editor');
        $this->db->prepare('DELETE FROM invites WHERE space_id = ? AND code = ?')->execute([$space['id'], strtoupper($params['code'])]);
        json(['ok' => true]);
    }

    /** GET /v1/invites/{code} — public preview (requires only X-App-Key). */
    public function preview(array $params): void
    {
        $inv = $this->validInvite($params['code']);
        $space = $this->space($inv['space_id']);
        $st = $this->db->prepare('SELECT name FROM users WHERE id = ?');
        $st->execute([$inv['inviter_id']]);
        $inviter = $st->fetch();
        $me = auth_optional();
        json([
            'code' => $inv['code'],
            'space' => [
                'id' => $space['id'],
                'name' => $space['name'],
                'type' => $space['type'],
                'currency' => $space['currency'],
                'icon' => $space['icon'],
                'color' => $space['color'],
                'member_count' => $this->memberCount($space['id']),
            ],
            'inviter' => $inviter ? $inviter['name'] : null,
            'role' => $inv['role'],
            'expires_at' => $inv['expires_at'],
            'already_member' => $me ? ($this->membership($space['id'], $me['id']) !== null) : false,
        ]);
    }

    /** POST /v1/invites/{code}/accept (auth) */
    public function accept(array $params): void
    {
        $u = auth();
        $inv = $this->validInvite($params['code']);
        $space = $this->space($inv['space_id']);
        if ((int) $space['archived'] === 1) {
            fail('space_archived', 'This space is archived', 409);
        }
        if ($this->membership($space['id'], $u['id'])) {
            json(['space' => $this->spaceOut($space, ['my_role' => $this->membership($space['id'], $u['id'])['role'], 'member_count' => $this->memberCount($space['id'])]), 'already_member' => true]);
        }
        if ($this->memberCount($space['id']) >= (int) config('limits.max_members_per_space', 50)) {
            fail('limit_reached', 'This space is full', 409);
        }
        $this->db->beginTransaction();
        $this->db->prepare('INSERT INTO space_members (space_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)')->execute([$space['id'], $u['id'], $inv['role'], now_ms()]);
        $this->db->prepare('UPDATE invites SET uses = uses + 1 WHERE code = ?')->execute([$inv['code']]);
        $this->db->prepare('UPDATE spaces SET updated_at = ? WHERE id = ?')->execute([now_ms(), $space['id']]);
        Activity::log($this->db, $space['id'], $u['id'], 'member_joined', 'member', $u['id'], ['via' => $inv['code'], 'role' => $inv['role']]);
        $this->db->commit();
        Push::toSpace($space['id'], $u['id'], $space['name'], $u['name'] . ' joined the space', ['kind' => 'member_joined']);
        json(['space' => $this->spaceOut($space, ['my_role' => $inv['role'], 'member_count' => $this->memberCount($space['id'])]), 'already_member' => false], 201);
    }

    // -------------------------------------------------------------- helpers

    private function validInvite(string $code): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 6) {
            fail('not_found', 'Invite not found', 404);
        }
        $st = $this->db->prepare('SELECT * FROM invites WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $inv = $st->fetch();
        if (!$inv) {
            fail('not_found', 'Invite not found', 404);
        }
        if ($inv['expires_at'] !== null && strtotime($inv['expires_at'] . ' UTC') < time()) {
            fail('invite_expired', 'This invite has expired', 410);
        }
        if ($inv['max_uses'] !== null && (int) $inv['uses'] >= (int) $inv['max_uses']) {
            fail('invite_exhausted', 'This invite has been used up', 410);
        }
        return $inv;
    }

    private function freshCode(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($k = 0; $k < 6; $k++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $st = $this->db->prepare('SELECT 1 FROM invites WHERE code = ?');
            $st->execute([$code]);
            if (!$st->fetch()) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate a unique invite code');
    }
}
