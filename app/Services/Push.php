<?php
/**
 * Push notifications — APNs over HTTP/2 using curl and a provider token
 * (ES256 JWT signed with the .p8 key from config). FCM can be added later.
 *
 * Everything is guarded: when APNs is not configured, curl lacks HTTP/2, or the
 * key file is missing, the call is logged and silently skipped so the API
 * request that triggered it still succeeds.
 *
 * Config (apns.*): key_id, team_id, p8_path, topic (bundle id), sandbox (bool).
 */

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use PDO;
use Throwable;

class Push
{
    /**
     * Notify every device of the given users (except $excludeUserId).
     *
     * @param string[] $userIds
     * @param array    $data   extra payload keys (space_id, kind, ...)
     */
    public static function toUsers(array $userIds, ?string $excludeUserId, string $title, string $body, array $data = []): void
    {
        $userIds = array_values(array_unique(array_filter($userIds, function ($id) use ($excludeUserId) {
            return $id && $id !== $excludeUserId;
        })));
        if (!$userIds) {
            return;
        }
        try {
            $pdo = db_try();
            if (!$pdo) {
                return;
            }
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $st = $pdo->prepare("SELECT id, user_id, platform, push_token FROM devices WHERE user_id IN ({$in}) AND push_token IS NOT NULL AND push_token <> ''");
            $st->execute($userIds);
            $devices = $st->fetchAll();
            foreach ($devices as $d) {
                if ($d['platform'] === 'ios') {
                    self::apns((string) $d['push_token'], $title, $body, $data, (int) $d['id']);
                } else {
                    // Android/FCM: not wired yet (spec: "FCM later").
                    log_msg('push', 'skip non-ios device ' . $d['id']);
                }
            }
        } catch (Throwable $e) {
            log_msg('push', 'toUsers failed: ' . $e->getMessage());
        }
    }

    /** Notify all members of a space except the actor. */
    public static function toSpace(string $spaceId, ?string $actorUserId, string $title, string $body, array $data = []): void
    {
        try {
            $pdo = db_try();
            if (!$pdo) {
                return;
            }
            $st = $pdo->prepare('SELECT user_id FROM space_members WHERE space_id = ?');
            $st->execute([$spaceId]);
            $ids = array_column($st->fetchAll(), 'user_id');
            $data['space_id'] = $spaceId;
            self::toUsers($ids, $actorUserId, $title, $body, $data);
        } catch (Throwable $e) {
            log_msg('push', 'toSpace failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- APNs

    /** Send one alert to one APNs device token. Returns true on 200. */
    public static function apns(string $deviceToken, string $title, string $body, array $data = [], ?int $deviceRowId = null): bool
    {
        $keyId = (string) config('apns.key_id', '');
        $teamId = (string) config('apns.team_id', '');
        $p8 = (string) config('apns.p8_path', '');
        $topic = (string) config('apns.topic', '');
        if ($keyId === '' || $teamId === '' || $p8 === '' || $topic === '') {
            log_msg('push', 'APNs not configured; skipping');
            return false;
        }
        if (!defined('CURL_HTTP_VERSION_2_0')) {
            log_msg('push', 'curl without HTTP/2 support; cannot send APNs');
            return false;
        }
        $jwt = self::providerToken($keyId, $teamId, $p8);
        if ($jwt === null) {
            return false;
        }

        $host = config('apns.sandbox', false) ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $url = $host . '/3/device/' . $deviceToken;
        $payload = [
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
                'thread-id' => $data['space_id'] ?? 'general',
            ],
        ];
        foreach ($data as $k => $v) {
            $payload[$k] = $v;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $topic,
                'apns-push-type: alert',
                'apns-priority: 10',
                'apns-expiration: ' . (time() + 3600),
                'content-type: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status === 200) {
            return true;
        }
        $reason = '';
        if (is_string($resp) && $resp !== '') {
            $j = json_decode($resp, true);
            $reason = is_array($j) ? (string) ($j['reason'] ?? '') : $resp;
        }
        log_msg('push', "APNs {$status} {$reason} {$err} token=" . substr($deviceToken, 0, 12) . '…');

        // Dead tokens: drop the device so we stop retrying.
        if ($deviceRowId && in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            try {
                db()->prepare('DELETE FROM devices WHERE id = ?')->execute([$deviceRowId]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        // Expired/invalid provider token: forget the cache so the next send mints a new one.
        if (in_array($reason, ['ExpiredProviderToken', 'InvalidProviderToken'], true)) {
            @unlink(CACHE_DIR . '/apns_token.json');
        }
        return false;
    }

    /**
     * ES256 provider token (cached ~50 minutes; Apple allows up to 60).
     */
    private static function providerToken(string $keyId, string $teamId, string $p8Path): ?string
    {
        $cacheFile = CACHE_DIR . '/apns_token.json';
        if (is_file($cacheFile)) {
            $c = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($c) && !empty($c['jwt']) && (time() - (int) ($c['iat'] ?? 0)) < 3000 && ($c['kid'] ?? '') === $keyId) {
                return $c['jwt'];
            }
        }
        if (!is_file($p8Path)) {
            log_msg('push', 'APNs .p8 not found at ' . $p8Path);
            return null;
        }
        $key = openssl_pkey_get_private((string) file_get_contents($p8Path));
        if ($key === false) {
            log_msg('push', 'APNs .p8 could not be loaded: ' . openssl_error_string());
            return null;
        }
        $iat = time();
        $h = Auth::b64url(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $p = Auth::b64url(json_encode(['iss' => $teamId, 'iat' => $iat]));
        $der = '';
        if (!openssl_sign($h . '.' . $p, $der, $key, OPENSSL_ALGO_SHA256)) {
            log_msg('push', 'APNs sign failed: ' . openssl_error_string());
            return null;
        }
        $jwt = $h . '.' . $p . '.' . Auth::b64url(self::derToRaw($der, 32));
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode(['jwt' => $jwt, 'iat' => $iat, 'kid' => $keyId]), LOCK_EX);
        return $jwt;
    }

    /** Convert a DER ECDSA signature (SEQUENCE of two INTEGERs) to fixed-size r||s. */
    private static function derToRaw(string $der, int $partLen): string
    {
        $pos = 2; // skip SEQUENCE tag + length (signatures are < 128 bytes)
        if (ord($der[1]) & 0x80) {
            $pos = 2 + (ord($der[1]) & 0x7f);
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // INTEGER tag
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $int = ltrim($int, "\x00");
            $out .= str_pad($int, $partLen, "\x00", STR_PAD_LEFT);
        }
        return $out;
    }
}
