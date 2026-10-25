<?php
/**
 * Sign in with Apple — identity token verification without any library.
 *
 * 1. Fetch Apple's JWKS (https://appleid.apple.com/auth/keys), cached 24h in _private/cache/.
 * 2. Pick the key by the token's `kid`, build an RSA public key PEM from (n, e).
 * 3. Verify RS256 with openssl_verify.
 * 4. Check iss, aud (one of config apple.bundle_ids), exp.
 *
 * Returns the token claims (sub, email, email_verified, ...) or throws.
 */

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use RuntimeException;

class AppleAuth
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';

    /**
     * @throws RuntimeException on any verification failure
     */
    public static function verifyIdentityToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed identity token');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(Auth::b64urlDecode($h64), true);
        $claims = json_decode(Auth::b64urlDecode($p64), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new RuntimeException('Malformed identity token');
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new RuntimeException('Unsupported token algorithm');
        }

        $jwk = self::findKey((string) $header['kid'], false);
        if ($jwk === null) {
            // Apple rotates keys: refresh the cache once before giving up.
            $jwk = self::findKey((string) $header['kid'], true);
        }
        if ($jwk === null) {
            throw new RuntimeException('Unknown Apple signing key');
        }

        $pem = self::rsaPublicKeyPem(Auth::b64urlDecode($jwk['n']), Auth::b64urlDecode($jwk['e']));
        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            throw new RuntimeException('Could not load Apple public key');
        }
        $ok = openssl_verify($h64 . '.' . $p64, Auth::b64urlDecode($s64), $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Identity token signature invalid');
        }

        if (($claims['iss'] ?? '') !== self::ISSUER) {
            throw new RuntimeException('Identity token issuer mismatch');
        }
        $allowedAud = (array) config('apple.bundle_ids', []);
        $aud = $claims['aud'] ?? '';
        $audList = is_array($aud) ? $aud : [$aud];
        if ($allowedAud && !array_intersect($audList, $allowedAud)) {
            throw new RuntimeException('Identity token audience mismatch');
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new RuntimeException('Identity token expired');
        }
        if (empty($claims['sub'])) {
            throw new RuntimeException('Identity token has no subject');
        }
        return $claims;
    }

    /** Load JWKS (cached) and return the key with the given kid. */
    private static function findKey(string $kid, bool $forceRefresh): ?array
    {
        $cacheFile = CACHE_DIR . '/apple_jwks.json';
        $ttl = (int) config('apple.jwks_cache_seconds', 86400);
        $keys = null;

        if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $keys = json_decode((string) file_get_contents($cacheFile), true);
        }
        if (!is_array($keys) || empty($keys['keys'])) {
            $res = Http::get(self::JWKS_URL, ['Accept: application/json'], 10);
            if ($res['status'] === 200) {
                $keys = json_decode($res['body'], true);
                if (is_array($keys) && !empty($keys['keys'])) {
                    if (!is_dir(CACHE_DIR)) {
                        @mkdir(CACHE_DIR, 0775, true);
                    }
                    @file_put_contents($cacheFile, $res['body'], LOCK_EX);
                }
            } else {
                log_msg('auth', 'Apple JWKS fetch failed: HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''));
                // fall back to stale cache if any
                if (is_file($cacheFile)) {
                    $keys = json_decode((string) file_get_contents($cacheFile), true);
                }
            }
        }
        if (!is_array($keys) || empty($keys['keys'])) {
            return null;
        }
        foreach ($keys['keys'] as $k) {
            if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA' && !empty($k['n']) && !empty($k['e'])) {
                return $k;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ DER/PEM

    /** Build a PEM "PUBLIC KEY" (SubjectPublicKeyInfo) from raw RSA modulus and exponent. */
    public static function rsaPublicKeyPem(string $n, string $e): string
    {
        $rsaKey = self::derSeq(self::derInt($n) . self::derInt($e));
        $algo = self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption OID + NULL
        $spki = self::derSeq($algo . self::derBitString($rsaKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\x00");
        if ($bin === '' || (ord($bin[0]) & 0x80)) {
            $bin = "\x00" . $bin; // keep it positive
        }
        return "\x02" . self::derLen(strlen($bin)) . $bin;
    }

    private static function derSeq(string $inner): string
    {
        return "\x30" . self::derLen(strlen($inner)) . $inner;
    }

    private static function derBitString(string $inner): string
    {
        return "\x03" . self::derLen(strlen($inner) + 1) . "\x00" . $inner;
    }
}
