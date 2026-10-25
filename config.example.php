<?php
/**
 * Expense Manager v3 sync API — configuration template.
 *
 * Copy this file to  _private/config.php  (sibling of public/, NOT inside it)
 * and fill in the values. Never commit the real file.
 *
 * Every key is read with config('section.key', default), so you can leave out
 * anything you do not use; the defaults shown here are what the code assumes.
 */

return [

    // -------------------------------------------------------------- general
    'app_name'  => 'Expense Manager',
    'debug'     => false,          // true = error details in responses, OTP codes in mail log. NEVER in production.

    // Shared secret the app sends as  X-App-Key  on every request. Empty string disables the check.
    // Generate: php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
    'app_key'   => 'CHANGE_ME_app_key',

    // Secret for signing login tokens (HS256) and hashing OTP codes. 32+ random bytes.
    // Generate: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'jwt_secret' => 'CHANGE_ME_jwt_secret_at_least_32_chars',

    // Token for GET /v1/admin/stats (header X-Admin-Token). Empty disables the endpoint.
    'admin_token' => 'CHANGE_ME_admin_token',

    // ------------------------------------------------------------- database
    'db' => [
        'host' => 'localhost',     // 20i: the MySQL hostname shown in My20i -> MySQL Databases (often sdb-XX.hosting.stackcp.net)
        'port' => 3306,
        'name' => 'sync_db',
        'user' => 'sync_user',
        'pass' => 'CHANGE_ME',
        // Optional: full DSN overrides host/port/name (e.g. 'sqlite:/path/dev.sqlite' for local tests)
        // 'dsn' => null,
    ],

    // ----------------------------------------------------------------- auth
    'auth' => [
        'token_ttl_days'        => 30,   // access token lifetime; refresh = re-login
        'issuer'                => 'sync.imrashid.net',
        'otp_ttl_minutes'       => 10,
        'otp_max_attempts'      => 5,
        'otp_debug_return_code' => false, // with debug=true, returns the code in the OTP response (dev only)
    ],

    // Sign in with Apple. The identity token is verified against Apple's public keys (JWKS);
    // only the bundle id(s) are needed for the audience check. team_id/key_id/p8 are reserved
    // for the token-exchange flow (not used by the app today) — leave empty if unused.
    'apple' => [
        'bundle_ids' => ['moneymanager.expensemanager.dailyexpensemanager'],
        'team_id'    => '',          // e.g. ABCDE12345 (TECH LOGIC HOST team)
        'key_id'     => '',          // Sign in with Apple key id (if you later add server-to-server calls)
        'p8_path'    => __DIR__ . '/AuthKey_SIWA.p8',
        'jwks_cache_seconds' => 86400,
    ],

    // Google Sign-In: every OAuth client id the app may use (iOS client, Android client, Web client).
    'google_client_ids' => [
        // '1234567890-abc.apps.googleusercontent.com',
    ],

    // -------------------------------------------------------------- push
    // APNs auth key (.p8) from developer.apple.com -> Keys (enable "Apple Push Notifications service").
    // Upload the .p8 into _private/ (never into public/). All four values required for push to be sent;
    // when any is missing, pushes are skipped and logged.
    'apns' => [
        'key_id'  => '',                           // e.g. 7X3K2ABCDE
        'team_id' => '',                           // e.g. ABCDE12345
        'p8_path' => __DIR__ . '/AuthKey_APNS.p8',
        'topic'   => 'moneymanager.expensemanager.dailyexpensemanager', // bundle id
        'sandbox' => false,                        // true for TestFlight/debug builds signed with a development profile
    ],

    // -------------------------------------------------------------- mail
    'mail' => [
        'driver'          => 'mail',               // 'mail' = PHP mail() via the host's MTA; 'log' = only write to _private/logs
        'from'            => 'no-reply@imrashid.net', // must be a mailbox/alias on a domain hosted on the same 20i account for good deliverability
        'from_name'       => 'Expense Manager',
        'reply_to'        => 'support@imrashid.net',
        'sendmail_params' => '',                   // e.g. '-f no-reply@imrashid.net' to set the envelope sender (helps SPF)
    ],

    // ----------------------------------------------------------------- AI
    'ai' => [
        'openai_key'          => '',               // sk-... (server only; the app never sees it)
        'model'               => 'gpt-4o-mini',    // for /v1/ai/ask
        'vision_model'        => 'gpt-4o-mini',    // for /v1/ai/receipt (must accept image input)
        'max_tokens'          => 800,
        'timeout'             => 60,
        'require_pro'         => true,             // require header X-Pro-Receipt
        'daily_limit_receipt' => 20,               // per user per day
        'daily_limit_ask'     => 30,
        'max_image_bytes'     => 8 * 1024 * 1024,
    ],

    // --------------------------------------------------------------- FX
    'fx' => [
        'cache_seconds' => 86400,                  // open.er-api.com is fetched at most once a day
    ],

    // ------------------------------------------------------------ invites
    'invites' => [
        'base_url'          => 'https://imrashid.net/j/', // landing page on imrashid.net (Universal Link)
        'default_expires_h' => 168,                // 7 days; 0 = never
        'default_max_uses'  => 10,                 // 0 = unlimited
    ],

    // ---------------------------------------------------------- limits
    'rate_limits' => [
        'per_ip_per_minute'            => 240,     // global, all endpoints except /v1/health
        'auth_per_ip_per_hour'         => 60,      // Apple/Google logins
        'otp_per_email_per_hour'       => 5,
        'otp_per_ip_per_hour'          => 20,
        'otp_verify_per_ip_per_hour'   => 60,
    ],
    'limits' => [
        'max_spaces_per_user'   => 100,            // abuse cap (the free-tier cap is enforced in the app)
        'max_members_per_space' => 50,
        'max_changes_per_push'  => 500,
    ],

    'defaults' => [
        'currency' => 'USD',
    ],
];
