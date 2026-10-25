<?php
/**
 * Self-contained JWT (HS256) issuing/verification and request authentication.
 *
 * - Access tokens live 30 days (config auth.token_ttl_days); refresh = re-login.
 * - Every request must carry `X-App-Key: <config app_key>` (checked in index.php).
 * - Protected routes read `Authorization: Bearer <jwt>`.
 */

declare(strict_types=1);

namespace App;

use PDO;

class Auth
{
    // ------------------------------------------------------------------ JWT

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $txt): string
    {
        $pad = strlen($txt) % 4;
        if ($pad) {
            $txt .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($txt, '-_', '+/'), true);
        return $out === false ? '' : $out;
    }

    /** Issue a signed HS256 token for a user id. */
    public static function issue(string $userId, array $extra = []): string
    {
        $secret = (string) config('jwt_secret', '');
        if (strlen($secret) < 16) {
            throw new \RuntimeException('jwt_secret missing or too short in config');
        }
        $ttlDays = (int) config('auth.token_ttl_days', 30);
        $now = time();
        $claims = array_merge($extra, [
            'iss' => config('auth.issuer', 'sync.imrashid.net'),
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $ttlDays * 86400,
            'jti' => bin2hex(random_bytes(8)),
        ]);
        $h = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64url(json_encode($claims));
        $sig = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        return $h . '.' . $p . '.' . self::b64url($sig);
    }

    /** Verify and decode; returns claims or null. */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64urlDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $secret = (string) config('jwt_secret', '');
        $expected = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        if (!hash_equals($expected, self::b64urlDecode($s))) {
            return null;
        }
        $claims = json_decode(self::b64urlDecode($p), true);
        if (!is_array($claims)) {
            return null;
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] < time()) {
            return null;
        }
        if (empty($claims['sub'])) {
            return null;
        }
        return $claims;
    }

    // ------------------------------------------------------------ request

    /** Bearer token from the Authorization header (or `?token=` for debugging when debug=true). */
    public static function bearer(): ?string
    {
        $h = header_value('Authorization');
        if ($h && preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
            return trim($m[1]);
        }
        if (config('debug', false) && !empty($_GET['token'])) {
            return (string) $_GET['token'];
        }
        return null;
    }

    /** Current user row or null (does not emit errors). */
    public static function currentUser(): ?array
    {
        static $resolved = false;
        static $user = null;
        if ($resolved) {
            return $user;
        }
        $resolved = true;
        $token = self::bearer();
        if (!$token) {
            return null;
        }
        $claims = self::verify($token);
        if (!$claims) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$claims['sub']]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'active') {
            return null;
        }
        // Tokens issued before a forced logout (token_valid_after) are rejected.
        if (!empty($row['token_valid_after']) && (int) $claims['iat'] < strtotime($row['token_valid_after'] . ' UTC')) {
            return null;
        }
        $user = $row;
        return $user;
    }

    /** User row or 401. */
    public static function requireUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            fail('unauthorized', 'Missing or invalid token', 401);
        }
        return $user;
    }

    /** Enforce the X-App-Key header when config app_key is set. */
    public static function requireAppKey(): void
    {
        $expected = (string) config('app_key', '');
        if ($expected === '') {
            return; // not configured => not enforced
        }
        $given = (string) (header_value('X-App-Key') ?? '');
        if ($given === '' || !hash_equals($expected, $given)) {
            fail('app_key_invalid', 'Missing or invalid X-App-Key', 401);
        }
    }

    /** Enforce X-Admin-Token for admin routes. */
    public static function requireAdmin(): void
    {
        $expected = (string) config('admin_token', '');
        $given = (string) (header_value('X-Admin-Token') ?? '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            fail('forbidden', 'Admin token required', 403);
        }
    }

    // --------------------------------------------------------------- users

    /**
     * Find-or-create a user by provider identity, then return [userRow, isNew].
     *
     * @param string      $provider 'apple' | 'google' | 'email'
     * @param string      $subject  provider subject (or the e-mail for OTP)
     * @param string|null $email
     * @param string|null $name
     */
    public static function upsertUser(string $provider, string $subject, ?string $email, ?string $name): array
    {
        $pdo = db();
        $email = $email ? mb_strtolower(trim($email)) : null;
        $col = $provider === 'apple' ? 'apple_sub' : ($provider === 'google' ? 'google_sub' : null);

        $row = null;
        if ($col) {
            $st = $pdo->prepare("SELECT * FROM users WHERE {$col} = ? LIMIT 1");
            $st->execute([$subject]);
            $row = $st->fetch() ?: null;
        }
        // Fall back to matching by e-mail so the same person logging in with a
        // different provider lands on the same account.
        if (!$row && $email) {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = ? LIMIT 1');
            $st->execute([$email, 'active']);
            $row = $st->fetch() ?: null;
        }

        $isNew = false;
        if (!$row) {
            $isNew = true;
            $id = uuid();
            $colors = ['#4F8EF7', '#F77F4F', '#4FC77F', '#B04FF7', '#F74F8E', '#4FC7F7', '#F7C44F'];
            $st = $pdo->prepare('INSERT INTO users (id, email, apple_sub, google_sub, name, avatar_color, currency, status, created_at, updated_at, last_login_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([
                $id,
                $email,
                $provider === 'apple' ? $subject : null,
                $provider === 'google' ? $subject : null,
                $name ?: ($email ? explode('@', $email)[0] : 'User'),
                $colors[random_int(0, count($colors) - 1)],
                (string) config('defaults.currency', 'USD'),
                'active',
                now(),
                now(),
                now(),
            ]);
        } else {
            $id = $row['id'];
            $sets = ['last_login_at = ?', 'updated_at = ?'];
            $vals = [now(), now()];
            if ($col && empty($row[$col])) {
                $sets[] = "{$col} = ?";
                $vals[] = $subject;
            }
            if ($email && empty($row['email'])) {
                $sets[] = 'email = ?';
                $vals[] = $email;
            }
            if ($name && ($row['name'] === '' || $row['name'] === null || $row['name'] === 'User')) {
                $sets[] = 'name = ?';
                $vals[] = $name;
            }
            $vals[] = $id;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }

        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        return [$st->fetch(), $isNew];
    }
}
