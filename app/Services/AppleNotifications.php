<?php
/**
 * App Store Server Notifications V2 receiver (all apps of the account).
 *
 * Apple POSTs {"signedPayload": "<JWS>"} for every purchase, renewal,
 * cancellation, refund … of ANY app that has this URL set in App Store
 * Connect. The JWS is verified against Apple's certificate chain (x5c →
 * Apple Root CA - G3, embedded below), then the
 * notification + its transaction / renewal info are stored. Apps appear in
 * the dashboard automatically the first time Apple sends something for them.
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

class AppleNotifications
{
    /** Apple Root CA - G3 (public certificate, DER base64) — sha256 63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179. */
    private const ROOT_B64 = 'MIICQzCCAcmgAwIBAgIILcX8iNLFS5UwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwSQXBwbGUgUm9vdCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTETMBEGA1UECgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcNMTQwNDMwMTgxOTA2WhcNMzkwNDMwMTgxOTA2WjBnMRswGQYDVQQDDBJBcHBsZSBSb290IENBIC0gRzMxJjAkBgNVBAsMHUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzB2MBAGByqGSM49AgEGBSuBBAAiA2IABJjpLz1AcqTtkyJygRMc3RCV8cWjTnHcFBbZDuWmBSp3ZHtfTjjTuxxEtX/1H7YyYl3J6YRbTzBPEVoA/VhYDKX1DyxNB0cTddqXl5dvMVztK517IDvYuVTZXpmkOlEKMaNCMEAwHQYDVR0OBBYEFLuw3qFYM4iapIqZ3r6966/ayySrMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMAoGCCqGSM49BAMDA2gAMGUCMQCD6cHEFl4aXTQY2e3v9GwOAEZLuN+yRhHFD/3meoyhpmvOwgPUnPWTxnS4at+qIxUCMG1mihDK1A3UT82NQz60imOlM27jbdoXt2QfyFMm+YhidDkLF1vLUagM6BgD56KyKA==';

    /** Create our tables when missing (called by the receiver and the admin panel). */
    public static function ensureTables(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $flag = CACHE_DIR . '/admin_schema_v1';
        if (is_file($flag)) {
            $done = true;
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS store_apps (
            bundle_id    VARCHAR(191) NOT NULL,
            app_apple_id BIGINT UNSIGNED NULL,
            name         VARCHAR(120) NULL,
            first_seen   DATETIME     NOT NULL,
            last_seen    DATETIME     NOT NULL,
            PRIMARY KEY (bundle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS store_events (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_uuid       VARCHAR(64)  NOT NULL,
            notification_type       VARCHAR(48)  NOT NULL,
            subtype                 VARCHAR(48)  NULL,
            environment             VARCHAR(16)  NULL,
            bundle_id               VARCHAR(191) NULL,
            app_apple_id            BIGINT UNSIGNED NULL,
            product_id              VARCHAR(191) NULL,
            product_type            VARCHAR(48)  NULL,
            transaction_id          VARCHAR(64)  NULL,
            original_transaction_id VARCHAR(64)  NULL,
            app_account_token       VARCHAR(64)  NULL,
            storefront              VARCHAR(8)   NULL,
            currency                VARCHAR(8)   NULL,
            price_milli             BIGINT       NULL,
            offer_type              TINYINT      NULL,
            transaction_reason      VARCHAR(16)  NULL,
            purchase_date           DATETIME     NULL,
            expires_date            DATETIME     NULL,
            signed_date             DATETIME     NULL,
            verified                TINYINT(1)   NOT NULL DEFAULT 0,
            raw                     LONGTEXT     NULL,
            created_at              DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_uuid (notification_uuid),
            KEY ix_store_created (created_at),
            KEY ix_store_app (bundle_id, created_at),
            KEY ix_store_otx (original_transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username      VARCHAR(64)  NOT NULL,
            pass_hash     VARCHAR(255) NOT NULL,
            created_at    DATETIME     NOT NULL,
            last_login_at DATETIME     NULL,
            last_login_ip VARCHAR(45)  NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        @file_put_contents($flag, now());
        $done = true;
    }

    /** Handle one POST from Apple. Returns HTTP status to send. */
    public static function receive(PDO $db, string $body): int
    {
        self::ensureTables($db);
        $j = json_decode($body, true);
        $jws = is_array($j) ? (string) ($j['signedPayload'] ?? '') : '';
        if ($jws === '') {
            return 400;
        }
        $verified = false;
        $payload = self::verifyJws($jws, $verified);
        if ($payload === null) {
            log_msg('apple', 'undecodable notification');
            return 400;
        }
        $data = $payload['data'] ?? [];
        $tx = [];
        $renew = [];
        if (!empty($data['signedTransactionInfo'])) {
            $ok = false;
            $tx = self::verifyJws((string) $data['signedTransactionInfo'], $ok) ?? [];
            $verified = $verified && $ok;
        }
        if (!empty($data['signedRenewalInfo'])) {
            $ok = false;
            $renew = self::verifyJws((string) $data['signedRenewalInfo'], $ok) ?? [];
        }
        $ms = function ($v): ?string {
            return $v ? gmdate('Y-m-d H:i:s', (int) floor(((float) $v) / 1000)) : null;
        };
        $bundle = (string) ($data['bundleId'] ?? ($tx['bundleId'] ?? ''));
        $uuid = (string) ($payload['notificationUUID'] ?? bin2hex(random_bytes(16)));

        $st = $db->prepare('INSERT IGNORE INTO store_events
            (notification_uuid, notification_type, subtype, environment, bundle_id, app_apple_id, product_id, product_type,
             transaction_id, original_transaction_id, app_account_token, storefront, currency, price_milli, offer_type,
             transaction_reason, purchase_date, expires_date, signed_date, verified, raw, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $uuid,
            (string) ($payload['notificationType'] ?? 'UNKNOWN'),
            $payload['subtype'] ?? null,
            $data['environment'] ?? ($tx['environment'] ?? null),
            $bundle ?: null,
            isset($data['appAppleId']) ? (int) $data['appAppleId'] : null,
            $tx['productId'] ?? null,
            $tx['type'] ?? null,
            isset($tx['transactionId']) ? (string) $tx['transactionId'] : null,
            isset($tx['originalTransactionId']) ? (string) $tx['originalTransactionId'] : null,
            $tx['appAccountToken'] ?? null,
            $tx['storefront'] ?? null,
            $tx['currency'] ?? null,
            isset($tx['price']) ? (int) $tx['price'] : null,
            isset($tx['offerType']) ? (int) $tx['offerType'] : null,
            $tx['transactionReason'] ?? null,
            $ms($tx['purchaseDate'] ?? null),
            $ms($tx['expiresDate'] ?? null),
            $ms($payload['signedDate'] ?? null),
            $verified ? 1 : 0,
            json_encode(['notification' => $payload, 'transaction' => $tx, 'renewal' => $renew], JSON_UNESCAPED_SLASHES),
            now(),
        ]);

        if ($bundle !== '') {
            $up = $db->prepare('INSERT INTO store_apps (bundle_id, app_apple_id, first_seen, last_seen) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), app_apple_id = COALESCE(VALUES(app_apple_id), app_apple_id)');
            $up->execute([$bundle, isset($data['appAppleId']) ? (int) $data['appAppleId'] : null, now(), now()]);
            self::fillAppName($db, $bundle);
        }
        return 200;
    }

    /** New app → look its public name up on the App Store (once). */
    private static function fillAppName(PDO $db, string $bundle): void
    {
        $st = $db->prepare('SELECT name FROM store_apps WHERE bundle_id = ?');
        $st->execute([$bundle]);
        if ((string) $st->fetchColumn() !== '') {
            return;
        }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 4]]);
            $raw = @file_get_contents('https://itunes.apple.com/lookup?bundleId=' . urlencode($bundle), false, $ctx);
            $j = $raw ? json_decode($raw, true) : null;
            $name = $j['results'][0]['trackName'] ?? null;
            if ($name) {
                $db->prepare('UPDATE store_apps SET name = ? WHERE bundle_id = ? AND name IS NULL')->execute([mb_substr((string) $name, 0, 120), $bundle]);
            }
        } catch (\Throwable $e) {
            // name can be set by hand on the Apps page
        }
    }

    // ------------------------------------------------------------------
    // JWS (ES256, x5c chain) verification
    // ------------------------------------------------------------------

    /** Decode a JWS; $verified tells whether Apple's signature + chain checked out. */
    public static function verifyJws(string $jws, bool &$verified): ?array
    {
        $verified = false;
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(self::b64($parts[0]), true);
        $payload = json_decode(self::b64($parts[1]), true);
        if (!is_array($payload)) {
            return null;
        }
        try {
            $verified = is_array($header) && self::checkSignature($header, $parts);
        } catch (\Throwable $e) {
            log_msg('apple', 'verify error: ' . $e->getMessage());
            $verified = false;
        }
        return $payload;
    }

    private static function checkSignature(array $header, array $parts): bool
    {
        if (($header['alg'] ?? '') !== 'ES256' || empty($header['x5c']) || count($header['x5c']) < 3) {
            return false;
        }
        $pem = function (string $b64der): string {
            return "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64der, 64, "\n") . "-----END CERTIFICATE-----\n";
        };
        [$leaf, $inter, $root] = array_map($pem, array_slice($header['x5c'], 0, 3));

        // root must be exactly Apple Root CA - G3
        $appleRoot = self::appleRootDer();
        if ($appleRoot === null || base64_decode($header['x5c'][2]) !== $appleRoot) {
            return false;
        }
        if (openssl_x509_verify($inter, openssl_pkey_get_public($root)) !== 1) {
            return false;
        }
        if (openssl_x509_verify($leaf, openssl_pkey_get_public($inter)) !== 1) {
            return false;
        }
        // ES256 signature: raw r||s (64 bytes) → DER
        $sig = self::b64($parts[2]);
        if (strlen($sig) !== 64) {
            return false;
        }
        $der = self::rsToDer(substr($sig, 0, 32), substr($sig, 32));
        $key = openssl_pkey_get_public($leaf);
        return openssl_verify($parts[0] . '.' . $parts[1], $der, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function appleRootDer(): ?string
    {
        // Tests may point at their own root; production always uses Apple Root CA - G3.
        $override = (string) config('apple.test_root_b64', '');
        return base64_decode($override !== '' ? $override : self::ROOT_B64);
    }

    private static function rsToDer(string $r, string $s): string
    {
        $int = function (string $x): string {
            $x = ltrim($x, "\x00");
            if ($x === '' || (ord($x[0]) & 0x80)) {
                $x = "\x00" . $x;
            }
            return "\x02" . chr(strlen($x)) . $x;
        };
        $seq = $int($r) . $int($s);
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    private static function b64(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
