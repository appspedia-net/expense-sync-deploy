<?php
/**
 * Google Sign-In — id_token verification via Google's tokeninfo endpoint.
 * (Google does the signature check; we check aud/exp/issuer.)
 */

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class GoogleAuth
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo?id_token=';

    /**
     * @return array claims (sub, email, email_verified, name, picture, aud, ...)
     * @throws RuntimeException
     */
    public static function verifyIdToken(string $idToken): array
    {
        // Google sign-in is off until client ids are configured — never accept tokens minted for other apps.
        $allowed = (array) config('google_client_ids', []);
        if (!$allowed) {
            throw new RuntimeException('Google sign-in is not enabled');
        }
        if ($idToken === '' || strlen($idToken) > 4096) {
            throw new RuntimeException('Malformed id_token');
        }
        $res = Http::get(self::TOKENINFO_URL . rawurlencode($idToken), ['Accept: application/json'], 10);
        $info = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($info) || empty($info['sub'])) {
            $msg = is_array($info) && !empty($info['error_description']) ? $info['error_description'] : 'tokeninfo rejected the token';
            throw new RuntimeException('Google: ' . $msg);
        }
        if (!in_array($info['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new RuntimeException('Google token issuer mismatch');
        }
        if (!in_array($info['aud'] ?? '', $allowed, true)) {
            throw new RuntimeException('Google token audience mismatch');
        }
        if ((int) ($info['exp'] ?? 0) < time() - 60) {
            throw new RuntimeException('Google token expired');
        }
        if (isset($info['email_verified']) && !in_array($info['email_verified'], [true, 'true', 1, '1'], true)) {
            $info['email'] = null; // do not trust unverified e-mails for account matching
        }
        return $info;
    }
}
