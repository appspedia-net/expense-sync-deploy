<?php
/**
 * Expense Manager v3 — server bootstrap.
 *
 * Loads config, sets up autoloading, the PDO connection and the small set of
 * global helper functions used by every controller (json(), fail(), input(),
 * auth(), now(), now_ms(), uuid(), config(), db(), log_msg()).
 *
 * PHP 7.4 compatible. No Composer, no framework.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));                 // .../server (or .../sync.imrashid.net)
define('APP_DIR', APP_ROOT . '/app');
define('PRIVATE_DIR', APP_ROOT . '/_private');        // config.php, logs/, cache/ — never web-reachable
define('LOG_DIR', PRIVATE_DIR . '/logs');
define('CACHE_DIR', PRIVATE_DIR . '/cache');
define('APP_START', microtime(true));

// ---------------------------------------------------------------------------
// Autoloader: App\Foo\Bar  ->  app/Foo/Bar.php
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_DIR . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$GLOBALS['__config'] = (function (): array {
    $file = PRIVATE_DIR . '/config.php';
    if (!is_file($file)) {
        // Allow a local dev override next to the app (never commit a real one).
        $file = APP_ROOT . '/config.php';
    }
    if (!is_file($file)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'config_missing', 'message' => 'Create _private/config.php from config.example.php']]);
        exit;
    }
    $cfg = require $file;
    return is_array($cfg) ? $cfg : [];
})();

/**
 * Read a config value with dot notation: config('db.host'), config('apple.bundle_ids', []).
 *
 * @param mixed $default
 * @return mixed
 */
function config(string $key, $default = null)
{
    $node = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

// ---------------------------------------------------------------------------
// Error handling: everything becomes a JSON error, details go to the log.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', config('debug', false) ? '1' : '0');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    log_msg('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = config('debug', false) ? $e->getMessage() : 'Internal server error';
    fail('internal_error', $message, 500);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Append a line to _private/logs/{channel}-YYYY-MM-DD.log (silently ignores write failures). */
function log_msg(string $channel, string $message): void
{
    try {
        if (!is_dir(LOG_DIR)) {
            @mkdir(LOG_DIR, 0775, true);
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents(LOG_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
        // never let logging break a request
    }
}

/** Current UTC time as "YYYY-MM-DD HH:MM:SS" (for DATETIME columns). */
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Current unix time in milliseconds (for sync `updated_at` columns). */
function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

/** Random RFC 4122 v4 UUID. */
function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Send CORS headers (allow all origins, as the API is only usable with X-App-Key + JWT). */
function cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-App-Key, X-Admin-Token, X-Pro-Receipt, X-Device-Id');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Emit a JSON response and end the request.
 *
 * @param mixed $data
 */
function json($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        cors_headers();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Emit the standard error envelope {error:{code,message}} with the given HTTP status. */
function fail(string $code, string $message, int $status = 400, array $extra = []): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($extra) {
        $error = array_merge($error, $extra);
    }
    json(['error' => $error], $status);
}

/**
 * Parsed JSON request body (cached). Form bodies are returned as-is.
 * Returns [] for empty bodies; 400 on malformed JSON.
 */
function input(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false || empty($_POST)) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $cached = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if (!empty($_POST)) {
                return $cached = $_POST;
            }
            fail('invalid_json', 'Request body must be valid JSON', 400);
        }
        return $cached = $decoded;
    }
    return $cached = $_POST;
}

/** Read a request header (case-insensitive), or null. */
function header_value(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return trim((string) $_SERVER[$key]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return trim((string) $v);
            }
        }
    }
    return null;
}

/** Client IP (first X-Forwarded-For hop if present). */
function client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = explode(',', $xff);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Shared PDO connection (lazy). Returns null (and logs) when the DB is unreachable,
 * so /v1/health can still answer. Use db() in controllers — it throws a clean 503.
 */
function db_try(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failed) {
        return null;
    }
    try {
        $dsn = config('db.dsn');
        if (!$dsn) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                config('db.host', '127.0.0.1'),
                (int) config('db.port', 3306),
                config('db.name', '')
            );
        }
        $pdo = new PDO($dsn, (string) config('db.user', ''), (string) config('db.pass', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if (strpos($dsn, 'mysql:') === 0) {
            $pdo->exec("SET time_zone = '+00:00'");
        }
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        log_msg('error', 'DB connect failed: ' . $e->getMessage());
        return null;
    }
}

/** PDO connection or a 503 JSON error. */
function db(): PDO
{
    $pdo = db_try();
    if ($pdo === null) {
        fail('db_unavailable', 'Database unavailable', 503);
    }
    return $pdo;
}

/**
 * The authenticated user row (from the Bearer JWT) or a 401.
 * Cached for the request.
 */
function auth(): array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $user = \App\Auth::requireUser();
    return $user;
}

/** Authenticated user if a valid token is present, otherwise null (no 401). */
function auth_optional(): ?array
{
    return \App\Auth::currentUser();
}

/**
 * Validate that $value is one of $allowed; 400 otherwise.
 *
 * @param mixed $value
 */
function require_enum($value, array $allowed, string $field): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fail('validation', "Invalid value for {$field}. Allowed: " . implode('|', $allowed), 400, ['field' => $field]);
    }
    return $value;
}

/** Trim and length-limit a string input (null if empty and not required). */
function str_input(array $src, string $key, int $max = 255, bool $required = false, ?string $default = null): ?string
{
    $v = $src[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            fail('validation', "Missing field: {$key}", 400, ['field' => $key]);
        }
        return $default;
    }
    if (!is_scalar($v)) {
        fail('validation', "Field {$key} must be a string", 400, ['field' => $key]);
    }
    $v = trim((string) $v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

/** Decode a JSON text column into an array (empty array on null/invalid). */
function json_col(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

/** Public shape of a user row (never leaks provider subs or deleted-state internals). */
function user_public(array $u): array
{
    return [
        'id' => $u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'avatar_color' => $u['avatar_color'],
        'currency' => $u['currency'],
        'created_at' => $u['created_at'],
    ];
}
