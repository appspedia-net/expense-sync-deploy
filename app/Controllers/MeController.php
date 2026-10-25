<?php
/**
 * Current user: profile, device registration, account deletion.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;

class MeController extends Controller
{
    /** GET /v1/me */
    public function show(array $params): void
    {
        $u = auth();
        $st = $this->db->prepare('SELECT COUNT(*) AS c FROM space_members WHERE user_id = ?');
        $st->execute([$u['id']]);
        json(['user' => user_public($u), 'space_count' => (int) $st->fetch()['c']]);
    }

    /** PATCH /v1/me {name, avatar_color, currency} */
    public function update(array $params): void
    {
        $u = auth();
        $in = input();
        $sets = [];
        $vals = [];
        if (array_key_exists('name', $in)) {
            $name = str_input($in, 'name', 80, true);
            $sets[] = 'name = ?';
            $vals[] = $name;
        }
        if (array_key_exists('avatar_color', $in)) {
            $color = str_input($in, 'avatar_color', 16);
            if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                fail('validation', 'avatar_color must be #RRGGBB', 400, ['field' => 'avatar_color']);
            }
            $sets[] = 'avatar_color = ?';
            $vals[] = $color;
        }
        if (array_key_exists('currency', $in)) {
            $cur = strtoupper((string) str_input($in, 'currency', 3, true));
            if (!preg_match('/^[A-Z]{3}$/', $cur)) {
                fail('validation', 'currency must be a 3-letter ISO code', 400, ['field' => 'currency']);
            }
            $sets[] = 'currency = ?';
            $vals[] = $cur;
        }
        if (!$sets) {
            fail('validation', 'Nothing to update', 400);
        }
        $sets[] = 'updated_at = ?';
        $vals[] = now();
        $vals[] = $u['id'];
        $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        $st = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        json(['user' => user_public($st->fetch())]);
    }

    /** POST /v1/me/device {platform, push_token, app_version, device_id?, locale?} */
    public function device(array $params): void
    {
        $u = auth();
        $in = input();
        $platform = require_enum($in['platform'] ?? null, ['ios', 'android', 'web'], 'platform');
        $pushToken = str_input($in, 'push_token', 512);
        $appVersion = str_input($in, 'app_version', 32);
        $deviceId = str_input($in, 'device_id', 64) ?: (header_value('X-Device-Id') ?: null);
        $locale = str_input($in, 'locale', 16);

        // Identify the device by device_id when given, else by push token.
        $row = null;
        if ($deviceId) {
            $st = $this->db->prepare('SELECT id FROM devices WHERE user_id = ? AND device_id = ? LIMIT 1');
            $st->execute([$u['id'], $deviceId]);
            $row = $st->fetch() ?: null;
        }
        if (!$row && $pushToken) {
            $st = $this->db->prepare('SELECT id FROM devices WHERE user_id = ? AND push_token = ? LIMIT 1');
            $st->execute([$u['id'], $pushToken]);
            $row = $st->fetch() ?: null;
        }
        if ($pushToken) {
            // A push token belongs to one device/user: steal it from stale rows.
            $this->db->prepare('DELETE FROM devices WHERE push_token = ? AND NOT (user_id = ? AND id = ?)')
                ->execute([$pushToken, $u['id'], $row ? (int) $row['id'] : 0]);
        }
        if ($row) {
            $this->db->prepare('UPDATE devices SET platform = ?, push_token = COALESCE(?, push_token), app_version = ?, device_id = COALESCE(?, device_id), locale = ?, updated_at = ? WHERE id = ?')
                ->execute([$platform, $pushToken, $appVersion, $deviceId, $locale, now(), (int) $row['id']]);
            $id = (int) $row['id'];
        } else {
            $this->db->prepare('INSERT INTO devices (user_id, device_id, platform, push_token, app_version, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$u['id'], $deviceId, $platform, $pushToken, $appVersion, $locale, now(), now()]);
            $id = (int) $this->db->lastInsertId();
        }
        json(['ok' => true, 'device_id' => $id]);
    }

    /**
     * DELETE /v1/me — account deletion (App Store requirement).
     * Anonymises the user, drops devices/invites, removes memberships; spaces the
     * user owned are handed to the longest-standing remaining member or deleted
     * when empty. Shared transactions stay (attributed to "Deleted user").
     */
    public function destroy(array $params): void
    {
        $u = auth();
        $uid = $u['id'];
        $pdo = $this->db;
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT s.* FROM spaces s JOIN space_members m ON m.space_id = s.id WHERE m.user_id = ?');
            $st->execute([$uid]);
            $spaces = $st->fetchAll();

            foreach ($spaces as $space) {
                $pdo->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')->execute([$space['id'], $uid]);
                if ($space['owner_id'] === $uid) {
                    $st2 = $pdo->prepare('SELECT user_id, role FROM space_members WHERE space_id = ? ORDER BY CASE role WHEN \'editor\' THEN 0 ELSE 1 END, joined_at ASC LIMIT 1');
                    $st2->execute([$space['id']]);
                    $heir = $st2->fetch();
                    if ($heir) {
                        $pdo->prepare('UPDATE spaces SET owner_id = ?, updated_at = ? WHERE id = ?')->execute([$heir['user_id'], now_ms(), $space['id']]);
                        $pdo->prepare('UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ?')->execute(['owner', $space['id'], $heir['user_id']]);
                        Activity::log($pdo, $space['id'], $uid, 'member_left', null, null, ['new_owner' => $heir['user_id']]);
                    } else {
                        $this->purgeSpace($space['id']);
                    }
                } else {
                    Activity::log($pdo, $space['id'], $uid, 'member_left');
                }
            }

            $pdo->prepare('DELETE FROM devices WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM invites WHERE inviter_id = ?')->execute([$uid]);
            if ($u['email']) {
                $pdo->prepare('DELETE FROM otp_codes WHERE email = ?')->execute([$u['email']]);
            }
            $pdo->prepare('UPDATE users SET email = NULL, apple_sub = NULL, google_sub = NULL, name = ?, avatar_color = ?, status = ?, token_valid_after = ?, updated_at = ? WHERE id = ?')
                ->execute(['Deleted user', '#9E9E9E', 'deleted', now(), now(), $uid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_msg('auth', 'account deleted ' . $uid);
        json(['ok' => true, 'deleted' => true]);
    }

    /** Hard-delete a space and all its rows. */
    private function purgeSpace(string $spaceId): void
    {
        foreach (['sp_transactions', 'sp_categories', 'sp_budgets', 'sp_settlements', 'sp_comments', 'sp_recurring_rules', 'activity', 'invites', 'space_members'] as $t) {
            $this->db->prepare("DELETE FROM {$t} WHERE space_id = ?")->execute([$spaceId]);
        }
        $this->db->prepare('DELETE FROM spaces WHERE id = ?')->execute([$spaceId]);
    }
}
