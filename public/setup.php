<?php
/**
 * One-time installer for the Expense Manager sync API.
 *
 *  - Only runs while _private/config.php does NOT exist.
 *  - Requires ?t=<SETUP_TOKEN> matching the SETUP_TOKEN file next to this script
 *    (the file is created by the deployer and removed together with this script).
 *  - POST: writes _private/config.php from the submitted values, imports schema.sql,
 *    tests the DB connection, then deletes itself and the token file.
 *
 * Nothing secret is stored in git: all values are typed into this form once.
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$root    = dirname(__DIR__);
$cfgPath = $root . '/_private/config.php';
$tokFile = __DIR__ . '/SETUP_TOKEN';

function done(string $msg, int $code = 200): void {
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;padding:32px;max-width:720px"><h2>Expense Manager sync — setup</h2><pre style="white-space:pre-wrap;background:#f4f4f4;padding:14px;border-radius:8px">' . htmlspecialchars($msg) . '</pre></body>';
    exit;
}

if (is_file($cfgPath)) {
    @unlink(__FILE__); @unlink($tokFile);
    done('Already installed. This installer has removed itself.', 410);
}
if (!is_file($tokFile)) done('Setup token file missing.', 403);
$want = trim((string) file_get_contents($tokFile));
$got  = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
if ($want === '' || !hash_equals($want, $got)) done('Forbidden.', 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $t = htmlspecialchars($got); $phpv = PHP_VERSION;
    echo <<<HTML
<!doctype html><meta charset="utf-8"><title>Setup</title>
<body style="font-family:system-ui;padding:32px;max-width:720px">
<h2>Expense Manager sync — one-time setup</h2>
<p>PHP version: <b>{$phpv}</b></p>
<form method="post">
<input type="hidden" name="t" value="$t">
<fieldset><legend>Database</legend>
<label>Host <input name="db_host" required style="width:100%"></label><br>
<label>Name <input name="db_name" required style="width:100%"></label><br>
<label>User <input name="db_user" required style="width:100%"></label><br>
<label>Password <input name="db_pass" type="password" required style="width:100%"></label>
</fieldset>
<fieldset><legend>App secrets</legend>
<label>app_key <input name="app_key" required style="width:100%"></label><br>
<label>jwt_secret <input name="jwt_secret" required style="width:100%"></label><br>
<label>admin_token <input name="admin_token" required style="width:100%"></label>
</fieldset>
<fieldset><legend>Optional (can be edited later in _private/config.php)</legend>
<label>OpenAI key <input name="openai_key" style="width:100%"></label><br>
<label>Google client ids (comma separated) <input name="google_ids" style="width:100%"></label><br>
<label>Mail from <input name="mail_from" value="no-reply@imrashid.net" style="width:100%"></label>
</fieldset>
<p><button style="padding:10px 18px;font-size:16px">Install</button></p>
</form></body>
HTML;
    exit;
}

// ---- POST: write config
$req = ['db_host','db_name','db_user','db_pass','app_key','jwt_secret','admin_token'];
foreach ($req as $k) if (trim((string)($_POST[$k] ?? '')) === '') done("Missing field: $k", 400);
$v = fn(string $k) => trim((string) ($_POST[$k] ?? ''));

$tpl = file_get_contents($root . '/config.example.php');
if ($tpl === false) done('config.example.php not found', 500);
$q = fn(string $s) => var_export($s, true);
$rep = [
    "'CHANGE_ME_app_key'"                        => $q($v('app_key')),
    "'CHANGE_ME_jwt_secret_at_least_32_chars'"   => $q($v('jwt_secret')),
    "'CHANGE_ME_admin_token'"                    => $q($v('admin_token')),
    "'host' => 'localhost'"                      => "'host' => " . $q($v('db_host')),
    "'name' => 'sync_db'"                        => "'name' => " . $q($v('db_name')),
    "'user' => 'sync_user'"                      => "'user' => " . $q($v('db_user')),
    "'pass' => 'CHANGE_ME'"                      => "'pass' => " . $q($v('db_pass')),
    "'from'            => 'no-reply@imrashid.net'" => "'from'            => " . $q($v('mail_from') ?: 'no-reply@imrashid.net'),
];
if ($v('openai_key') !== '') $rep["'openai_key'          => ''"] = "'openai_key'          => " . $q($v('openai_key'));
$cfg = strtr($tpl, $rep);
if ($v('google_ids') !== '') {
    $ids = array_values(array_filter(array_map('trim', explode(',', $v('google_ids')))));
    $cfg = preg_replace("/'google_client_ids' => \[[^\]]*\]/s", "'google_client_ids' => " . var_export($ids, true), $cfg, 1);
}
foreach ($rep as $k => $_) if (strpos($tpl, $k) === false && strpos($k, 'openai') === false) done("Template key not found: $k", 500);

if (!is_dir($root . '/_private')) mkdir($root . '/_private', 0755, true);
foreach (['logs','cache'] as $d) if (!is_dir("$root/_private/$d")) mkdir("$root/_private/$d", 0775, true);
if (file_put_contents($cfgPath, $cfg) === false) done('Could not write _private/config.php', 500);
@chmod($cfgPath, 0640);

// ---- DB test + schema import
$log = [];
try {
    $pdo = new PDO("mysql:host={$v('db_host')};dbname={$v('db_name')};charset=utf8mb4", $v('db_user'), $v('db_pass'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $log[] = 'DB connection OK (' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ')';
    $sql = file_get_contents($root . '/schema.sql');
    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', (string) $sql)));
    $n = 0;
    foreach ($stmts as $s) { if ($s === '' || strpos($s, '--') === 0) continue; $pdo->exec($s); $n++; }
    $log[] = "schema.sql imported ($n statements)";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $log[] = 'tables: ' . implode(', ', $tables);
} catch (Throwable $e) {
    @unlink($cfgPath);
    done("Database error — config NOT saved, fix and retry:\n" . $e->getMessage(), 500);
}

@unlink(__FILE__); @unlink($tokFile);
$log[] = 'Installer removed itself.';
$log[] = 'Next: open /v1/health';
done(implode("\n", $log));
