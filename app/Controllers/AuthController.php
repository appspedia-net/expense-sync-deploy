<?php
/**
 * Authentication endpoints: Apple, Google, e-mail OTP.
 * All return {token, user, is_new}.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Services\AppleAuth;
use App\Services\GoogleAuth;
use App\Services\Mail;
use App\Services\RateLimit;
use Throwable;

class AuthController extends Controller
{
    /** POST /v1/auth/apple {identity_token, full_name?} */
    public function apple(array $params): void
    {
        $in = input();
        $token = str_input($in, 'identity_token', 8192, true);
        RateLimit::guard('auth:ip:' . client_ip(), (int) config('rate_limits.auth_per_ip_per_hour', 60), 3600);

        try {
            $claims = AppleAuth::verifyIdentityToken($token);
        } catch (Throwable $e) {
            log_msg('auth', 'apple failed: ' . $e->getMessage());
            fail('invalid_token', $e->getMessage(), 401);
        }

        $name = null;
        $fn = $in['full_name'] ?? null;
        if (is_array($fn)) {
            $name = trim(implode(' ', array_filter([$fn['givenName'] ?? $fn['given_name'] ?? '', $fn['familyName'] ?? $fn['family_name'] ?? ''])));
        } elseif (is_string($fn)) {
            $name = trim($fn);
        }
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;

        [$user, $isNew] = Auth::upsertUser('apple', (string) $claims['sub'], $email, $name ?: null);
        $this->respond($user, $isNew);
    }

    /** POST /v1/auth/google {id_token} */
    public function google(array $params): void
    {
        $in = input();
        $token = str_input($in, 'id_token', 4096, true);
        RateLimit::guard('auth:ip:' . client_ip(), (int) config('rate_limits.auth_per_ip_per_hour', 60), 3600);

        try {
            $info = GoogleAuth::verifyIdToken($token);
        } catch (Throwable $e) {
            log_msg('auth', 'google failed: ' . $e->getMessage());
            fail('invalid_token', $e->getMessage(), 401);
        }
        [$user, $isNew] = Auth::upsertUser('google', (string) $info['sub'], $info['email'] ?? null, $info['name'] ?? null);
        $this->respond($user, $isNew);
    }

    /** POST /v1/auth/otp/request {email} — 6-digit code, 10 min, 5/hour per e-mail. */
    public function otpRequest(array $params): void
    {
        $in = input();
        $email = mb_strtolower(trim((string) str_input($in, 'email', 254, true)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('validation', 'Invalid e-mail address', 400, ['field' => 'email']);
        }
        RateLimit::guard('otp:email:' . $email, (int) config('rate_limits.otp_per_email_per_hour', 5), 3600, 'Too many codes requested for this e-mail. Try again later.');
        RateLimit::guard('otp:ip:' . client_ip(), (int) config('rate_limits.otp_per_ip_per_hour', 20), 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) config('auth.otp_ttl_minutes', 10);
        $expires = gmdate('Y-m-d H:i:s', time() + $minutes * 60);

        // Invalidate earlier unused codes for this address, then store the new one hashed.
        $this->db->prepare('UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0')->execute([$email]);
        $this->db->prepare('INSERT INTO otp_codes (email, code_hash, expires_at, attempts, used, ip, created_at) VALUES (?, ?, ?, 0, 0, ?, ?)')
            ->execute([$email, $this->hashCode($email, $code), $expires, client_ip(), now()]);

        $sent = Mail::sendOtp($email, $code, $minutes);
        $resp = ['ok' => true, 'sent' => $sent, 'expires_in' => $minutes * 60];
        if (config('debug', false) && config('auth.otp_debug_return_code', false)) {
            $resp['debug_code'] = $code; // dev only — never enable in production
        }
        json($resp);
    }

    /** POST /v1/auth/otp/verify {email, code} */
    public function otpVerify(array $params): void
    {
        $in = input();
        $email = mb_strtolower(trim((string) str_input($in, 'email', 254, true)));
        $code = preg_replace('/\D/', '', (string) str_input($in, 'code', 12, true));
        if (strlen($code) !== 6) {
            fail('validation', 'Code must be 6 digits', 400, ['field' => 'code']);
        }
        RateLimit::guard('otpv:ip:' . client_ip(), (int) config('rate_limits.otp_verify_per_ip_per_hour', 60), 3600);

        $st = $this->db->prepare('SELECT * FROM otp_codes WHERE email = ? AND used = 0 ORDER BY id DESC LIMIT 1');
        $st->execute([$email]);
        $otp = $st->fetch();
        if (!$otp) {
            fail('otp_invalid', 'No active code for this e-mail. Request a new one.', 400);
        }
        if (strtotime($otp['expires_at'] . ' UTC') < time()) {
            fail('otp_expired', 'Code expired. Request a new one.', 400);
        }
        if ((int) $otp['attempts'] >= (int) config('auth.otp_max_attempts', 5)) {
            $this->db->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);
            fail('otp_locked', 'Too many wrong attempts. Request a new code.', 400);
        }
        if (!hash_equals($otp['code_hash'], $this->hashCode($email, $code))) {
            $this->db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
            fail('otp_invalid', 'Wrong code', 400);
        }
        $this->db->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);

        [$user, $isNew] = Auth::upsertUser('email', $email, $email, null);
        $this->respond($user, $isNew);
    }

    // -------------------------------------------------------------- helpers

    private function hashCode(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, (string) config('jwt_secret', ''));
    }

    private function respond(array $user, bool $isNew): void
    {
        json([
            'token' => Auth::issue($user['id']),
            'token_type' => 'Bearer',
            'expires_in' => (int) config('auth.token_ttl_days', 30) * 86400,
            'user' => user_public($user),
            'is_new' => $isNew,
        ]);
    }
}
