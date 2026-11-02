<?php
/**
 * Owner dashboard at /admin-tech (never /admin).
 *
 *  /admin-tech                 overview: signups + App Store purchases (today / 7d / 30d), charts
 *  /admin-tech/users           users (search, CSV export), /admin-tech/users/{id} detail
 *  /admin-tech/purchases       App Store Server Notifications of ALL apps (filters), /purchases/{id}
 *  /admin-tech/apps            apps seen in notifications (auto), rename
 *  /admin-tech/spaces          shared spaces
 *  /admin-tech/ai              AI usage
 *  /admin-tech/account         change password
 *  /admin-tech/setup           one-time: create the first admin (needs the server admin token)
 *  /admin-tech/login|logout
 *
 * Session login (bcrypt password), CSRF tokens on every POST, login lockout
 * after repeated failures, noindex. Times are shown in admin.timezone
 * (default Asia/Karachi).
 */

declare(strict_types=1);

namespace App\Admin;

use App\Services\AppleNotifications;
use PDO;

class Panel
{
    public const BASE = '/admin-tech';

    private PDO $db;
    private string $path;
    private \DateTimeZone $tz;
    private int $offsetMin;

    public function __construct(string $path)
    {
        $this->db = db();
        $this->path = '/' . trim(substr($path, strlen(self::BASE)), '/');
        $this->tz = new \DateTimeZone((string) config('admin.timezone', 'Asia/Karachi'));
        $this->offsetMin = (int) ($this->tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC'))) / 60);
    }

    public function run(): void
    {
        header_remove('Access-Control-Allow-Origin');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        AppleNotifications::ensureTables($this->db);

        session_name('emadm');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => self::BASE,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_start();

        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $p = $this->path;

        if ($p === '/setup') { $this->setup($m); return; }
        if ($p === '/login') { $this->login($m); return; }
        if ($p === '/logout') { session_destroy(); $this->redirect('/login'); }

        if (!$this->adminCount()) { $this->redirect('/setup'); }
        $this->requireLogin();
        if ($m === 'POST') { $this->checkCsrf(); }

        if ($p === '/' || $p === '') { $this->overview(); return; }
        if ($p === '/users') { $this->users(); return; }
        if ($p === '/users-export') { $this->usersCsv(); return; }
        if (preg_match('#^/users/([0-9a-f-]{36})$#', $p, $mm)) { $this->user($mm[1], $m); return; }
        if ($p === '/purchases') { $this->purchases(); return; }
        if (preg_match('#^/purchases/(\d+)$#', $p, $mm)) { $this->purchase((int) $mm[1]); return; }
        if ($p === '/apps') { $this->apps($m); return; }
        if ($p === '/spaces') { $this->spaces(); return; }
        if ($p === '/ai') { $this->ai(); return; }
        if ($p === '/account') { $this->account($m); return; }
        http_response_code(404);
        $this->page('Not found', '<p>Page not found.</p>');
    }

    // ------------------------------------------------------------------ auth

    private function adminCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    private function requireLogin(): void
    {
        $ok = !empty($_SESSION['admin_id']) && (time() - (int) ($_SESSION['seen'] ?? 0)) < 4 * 3600;
        if (!$ok) {
            $_SESSION = [];
            $this->redirect('/login');
        }
        $_SESSION['seen'] = time();
    }

    private function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
    }

    private function checkCsrf(): void
    {
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            $this->page('Expired', '<p>Form expired, please go back and try again.</p>');
            exit;
        }
    }

    private function failures(bool $add = false): int
    {
        $key = 'adm:ip:' . client_ip();
        $win = (int) floor(time() / 900);
        if ($add) {
            $this->db->prepare('INSERT INTO rate_limits (rl_key, window_start, count) VALUES (?,?,1)
                ON DUPLICATE KEY UPDATE count = count + 1')->execute([$key, $win]);
        }
        $st = $this->db->prepare('SELECT COALESCE(SUM(count),0) FROM rate_limits WHERE rl_key = ? AND window_start >= ?');
        $st->execute([$key, $win - 1]);
        return (int) $st->fetchColumn();
    }

    private function login(string $m): void
    {
        $err = '';
        if ($m === 'POST') {
            if ($this->failures() >= 8) {
                $err = 'Too many attempts. Try again in 15 minutes.';
            } else {
                $st = $this->db->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
                $st->execute([trim((string) ($_POST['username'] ?? ''))]);
                $u = $st->fetch();
                if ($u && password_verify((string) ($_POST['password'] ?? ''), $u['pass_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION = ['admin_id' => (int) $u['id'], 'admin_name' => $u['username'], 'seen' => time()];
                    $this->db->prepare('UPDATE admin_users SET last_login_at = ?, last_login_ip = ? WHERE id = ?')
                        ->execute([now(), client_ip(), $u['id']]);
                    $this->redirect('/');
                }
                $this->failures(true);
                usleep(400000);
                $err = 'Wrong username or password.';
            }
        }
        $this->page('Sign in', $this->authForm('Sign in', $err, '
            <label>Username<input name="username" autocomplete="username" required autofocus></label>
            <label>Password<input name="password" type="password" autocomplete="current-password" required></label>'), false);
    }

    private function setup(string $m): void
    {
        if ($this->adminCount() > 0) {
            $this->redirect('/login');
        }
        $err = '';
        if ($m === 'POST') {
            $token = (string) config('admin_token', '');
            $user = trim((string) ($_POST['username'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            if ($this->failures() >= 8) {
                $err = 'Too many attempts. Try again in 15 minutes.';
            } elseif ($token === '' || !hash_equals($token, (string) ($_POST['admin_token'] ?? ''))) {
                $this->failures(true);
                $err = 'Server admin key is not correct.';
            } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $user)) {
                $err = 'Username: 3–64 letters, numbers or _ . @ -';
            } elseif (strlen($pass) < 10 || $pass !== (string) ($_POST['password2'] ?? '')) {
                $err = 'Password must be at least 10 characters and both entries must match.';
            } else {
                $this->db->prepare('INSERT INTO admin_users (username, pass_hash, created_at) VALUES (?,?,?)')
                    ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
                $this->redirect('/login');
            }
        }
        $this->page('Create admin', $this->authForm('Create your admin login', $err, '
            <p class="muted">One-time setup. Needs the server admin key (admin_token in _private/config.php).</p>
            <label>Server admin key<input name="admin_token" type="password" required></label>
            <label>Username<input name="username" autocomplete="username" required></label>
            <label>Password (min 10)<input name="password" type="password" autocomplete="new-password" required></label>
            <label>Repeat password<input name="password2" type="password" autocomplete="new-password" required></label>', 'Create'), false);
    }

    private function authForm(string $title, string $err, string $fields, string $btn = 'Sign in'): string
    {
        return '<div class="auth"><h1>' . $this->e($title) . '</h1>'
            . ($err ? '<div class="err">' . $this->e($err) . '</div>' : '')
            . '<form method="post">' . $this->csrfLite() . $fields . '<button>' . $btn . '</button></form></div>';
    }

    private function csrfLite(): string
    {
        return ''; // login/setup are protected by SameSite=Strict + lockout
    }

    private function account(string $m): void
    {
        $msg = '';
        if ($m === 'POST') {
            $st = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
            $st->execute([$_SESSION['admin_id']]);
            $u = $st->fetch();
            $new = (string) ($_POST['new'] ?? '');
            if (!$u || !password_verify((string) ($_POST['current'] ?? ''), $u['pass_hash'])) {
                $msg = '<div class="err">Current password is wrong.</div>';
            } elseif (strlen($new) < 10 || $new !== (string) ($_POST['new2'] ?? '')) {
                $msg = '<div class="err">New password: at least 10 characters, both entries must match.</div>';
            } else {
                $this->db->prepare('UPDATE admin_users SET pass_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                $msg = '<div class="ok">Password changed.</div>';
            }
        }
        $this->page('Account', '<h1>Account</h1>' . $msg . '<div class="card narrow"><form method="post">' . $this->csrf()
            . '<label>Current password<input type="password" name="current" required></label>'
            . '<label>New password<input type="password" name="new" required></label>'
            . '<label>Repeat new password<input type="password" name="new2" required></label>'
            . '<button>Change password</button></form></div>');
    }

    // ------------------------------------------------------------------ time helpers

    /** UTC 'Y-m-d H:i:s' of local midnight N days ago (0 = today). */
    private function localDayStartUtc(int $daysAgo = 0): string
    {
        $d = new \DateTime('today', $this->tz);
        if ($daysAgo) {
            $d->modify("-{$daysAgo} days");
        }
        $d->setTimezone(new \DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    }

    private function local(?string $utc, string $fmt = 'd M Y, H:i'): string
    {
        if (!$utc) {
            return '—';
        }
        $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        $d->setTimezone($this->tz);
        return $d->format($fmt);
    }

    private function count(string $sql, array $args = []): int
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    /** [ 'Y-m-d' => n ] for the last $days local days. */
    private function perDay(string $table, string $col, int $days, string $where = '1=1', array $args = []): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[(new \DateTime("today -{$i} days", $this->tz))->format('Y-m-d')] = 0;
        }
        $sql = "SELECT DATE(DATE_ADD($col, INTERVAL {$this->offsetMin} MINUTE)) d, COUNT(*) c FROM $table
                WHERE $col >= ? AND $where GROUP BY d";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge([$this->localDayStartUtc($days - 1)], $args));
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['d']])) {
                $out[$r['d']] = (int) $r['c'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ purchase helpers

    private const NEW_SALE = "('SUBSCRIBED','ONE_TIME_CHARGE','OFFER_REDEEMED')";

    private function envWhere(): array
    {
        $env = ($_GET['env'] ?? 'Production') === 'all' ? 'all' : (($_GET['env'] ?? '') === 'Sandbox' ? 'Sandbox' : 'Production');
        // Totals only count notifications whose Apple signature was verified (fakes can't inflate them).
        $v = config('admin.show_unverified', false) ? '1=1' : 'verified = 1';
        return $env === 'all' ? [$v, [], $env] : ["environment = ? AND $v", [$env], $env];
    }

    private function envTabs(string $env, string $extra = ''): string
    {
        $t = '';
        foreach (['Production' => 'Live (App Store)', 'Sandbox' => 'Sandbox / TestFlight', 'all' => 'All'] as $k => $label) {
            $t .= '<a class="chip' . ($env === $k ? ' on' : '') . '" href="?env=' . $k . $extra . '">' . $label . '</a>';
        }
        return '<div class="chips">' . $t . '</div>';
    }

    private function money(?int $milli, ?string $cur): string
    {
        if ($milli === null) {
            return '—';
        }
        return $this->e(($cur ?: '') . ' ' . number_format($milli / 1000, 2));
    }

    private function eventLabel(array $r): string
    {
        $t = $r['notification_type'];
        $s = $r['subtype'] ?? '';
        $map = [
            'SUBSCRIBED' => $s === 'RESUBSCRIBE' ? 'Re-subscribed' : 'New subscription',
            'ONE_TIME_CHARGE' => 'One-time purchase',
            'DID_RENEW' => 'Renewal',
            'OFFER_REDEEMED' => 'Offer redeemed',
            'REFUND' => 'Refund',
            'EXPIRED' => 'Expired',
            'DID_FAIL_TO_RENEW' => 'Payment failed',
            'GRACE_PERIOD_EXPIRED' => 'Grace period ended',
            'DID_CHANGE_RENEWAL_STATUS' => $s === 'AUTO_RENEW_DISABLED' ? 'Turned off auto-renew' : 'Turned on auto-renew',
            'DID_CHANGE_RENEWAL_PREF' => 'Changed plan',
            'REVOKE' => 'Revoked (Family Sharing)',
            'CONSUMPTION_REQUEST' => 'Refund requested',
            'REFUND_DECLINED' => 'Refund declined',
            'PRICE_INCREASE' => 'Price increase',
            'TEST' => 'Test notification',
        ];
        $label = $map[$t] ?? $t;
        if ((int) ($r['offer_type'] ?? 0) === 1 && in_array($t, ['SUBSCRIBED', 'DID_RENEW'], true) && (int) ($r['price_milli'] ?? 1) === 0) {
            $label .= ' (free trial)';
        }
        return $label;
    }

    private function appName(?string $bundle): string
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach ($this->db->query('SELECT bundle_id, name FROM store_apps') as $r) {
                $names[$r['bundle_id']] = $r['name'];
            }
        }
        if (!$bundle) {
            return '—';
        }
        return ($names[$bundle] ?? null) ?: $bundle;
    }

    // ------------------------------------------------------------------ pages

    private function overview(): void
    {
        [$ew, $ea, $env] = $this->envWhere();
        $today = $this->localDayStartUtc(0);
        $d7 = $this->localDayStartUtc(6);
        $d30 = $this->localDayStartUtc(29);
        $a = 'status = ?';

        $u = [
            'total' => $this->count("SELECT COUNT(*) FROM users WHERE $a", ['active']),
            'today' => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
            '7d' => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$d7]),
            '30d' => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$d30]),
            'active' => $this->count('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [gmdate('Y-m-d H:i:s', time() - 86400)]),
            'deleted' => $this->count("SELECT COUNT(*) FROM users WHERE $a", ['deleted']),
        ];
        $methods = [
            'Apple' => $this->count("SELECT COUNT(*) FROM users WHERE $a AND apple_sub IS NOT NULL", ['active']),
            'Google' => $this->count("SELECT COUNT(*) FROM users WHERE $a AND apple_sub IS NULL AND google_sub IS NOT NULL", ['active']),
            'Email code' => $this->count("SELECT COUNT(*) FROM users WHERE $a AND apple_sub IS NULL AND google_sub IS NULL", ['active']),
        ];
        $sales = function (string $from) use ($ew, $ea): int {
            return $this->count('SELECT COUNT(*) FROM store_events WHERE notification_type IN ' . self::NEW_SALE
                . " AND created_at >= ? AND $ew", array_merge([$from], $ea));
        };
        $ren = function (string $from) use ($ew, $ea): int {
            return $this->count("SELECT COUNT(*) FROM store_events WHERE notification_type = 'DID_RENEW' AND created_at >= ? AND $ew",
                array_merge([$from], $ea));
        };

        // today by app + product
        $st = $this->db->prepare("SELECT bundle_id, product_id, notification_type, subtype, offer_type, price_milli, currency
            FROM store_events WHERE created_at >= ? AND $ew");
        $st->execute(array_merge([$today], $ea));
        $rows = [];
        $revenue = [];
        foreach ($st->fetchAll() as $r) {
            $k = ($r['bundle_id'] ?? '') . '|' . ($r['product_id'] ?? '');
            $rows[$k] = $rows[$k] ?? ['app' => $r['bundle_id'], 'product' => $r['product_id'], 'new' => 0, 'trial' => 0, 'renew' => 0, 'refund' => 0, 'cancel' => 0];
            $t = $r['notification_type'];
            if (in_array($t, ['SUBSCRIBED', 'ONE_TIME_CHARGE', 'OFFER_REDEEMED'], true)) {
                if ((int) $r['offer_type'] === 1 && (int) $r['price_milli'] === 0) { $rows[$k]['trial']++; } else { $rows[$k]['new']++; }
            } elseif ($t === 'DID_RENEW') { $rows[$k]['renew']++; }
            elseif ($t === 'REFUND') { $rows[$k]['refund']++; }
            elseif ($t === 'DID_CHANGE_RENEWAL_STATUS' && $r['subtype'] === 'AUTO_RENEW_DISABLED') { $rows[$k]['cancel']++; }
            if (in_array($t, ['SUBSCRIBED', 'ONE_TIME_CHARGE', 'DID_RENEW', 'OFFER_REDEEMED'], true) && $r['price_milli'] !== null) {
                $c = $r['currency'] ?: '?';
                $revenue[$c] = ($revenue[$c] ?? 0) + (int) $r['price_milli'];
            }
        }
        $tbl = '';
        foreach ($rows as $r) {
            $tbl .= '<tr><td>' . $this->e($this->appName($r['app'])) . '</td><td>' . $this->e($r['product'] ?? '—') . '</td><td class="n">'
                . $r['new'] . '</td><td class="n">' . $r['trial'] . '</td><td class="n">' . $r['renew'] . '</td><td class="n">' . $r['refund']
                . '</td><td class="n">' . $r['cancel'] . '</td></tr>';
        }
        $rev = $revenue ? implode(' · ', array_map(function ($c, $v) { return $this->e($c . ' ' . number_format($v / 1000, 2)); }, array_keys($revenue), $revenue)) : '—';

        $signups = $this->perDay('users', 'created_at', 30);
        $salesDay = $this->perDay('store_events', 'created_at', 30, 'notification_type IN ' . self::NEW_SALE . " AND $ew", $ea);
        $renewDay = $this->perDay('store_events', 'created_at', 30, "notification_type = 'DID_RENEW' AND $ew", $ea);

        $card = function (string $label, $v, string $sub = '', bool $small = false) {
            return '<div class="kpi"><div class="kl">' . $label . '</div><div class="kv' . ($small ? ' sm' : '') . '">' . $v . '</div>' . ($sub ? '<div class="ks">' . $sub . '</div>' : '') . '</div>';
        };
        $html = '<h1>Overview <span class="muted small">' . $this->e((new \DateTime('now', $this->tz))->format('D d M Y, H:i')) . ' (' . $this->e($this->tz->getName()) . ')</span></h1>'
            . '<h2>Users (Expense Manager accounts)</h2><div class="kpis">'
            . $card('New today', $u['today']) . $card('Last 7 days', $u['7d']) . $card('Last 30 days', $u['30d'])
            . $card('Total users', $u['total'], $u['deleted'] . ' deleted') . $card('Active (24h)', $u['active'])
            . $card('Sign-in method', implode('<br>', array_map(function ($k, $v) { return "$k: $v"; }, array_keys($methods), $methods)), '', true) . '</div>'
            . '<h2>App Store purchases — all apps</h2>' . $this->envTabs($env)
            . '<div class="kpis">' . $card('New purchases today', $sales($today), 'incl. free trials') . $card('Renewals today', $ren($today))
            . $card('Purchases 7 days', $sales($d7)) . $card('Purchases 30 days', $sales($d30)) . $card('Revenue today (gross)', $rev, 'customer price, before Apple\'s cut', true) . '</div>'
            . '<div class="card"><h3>Today by app &amp; product</h3>'
            . ($tbl ? '<table><tr><th>App</th><th>Product</th><th>New</th><th>Free trials</th><th>Renewals</th><th>Refunds</th><th>Cancelled</th></tr>' . $tbl . '</table>'
                : '<p class="muted">No App Store events today' . ($env === 'Production' ? ' (live). Sandbox/TestFlight purchases are under the Sandbox tab.' : '.') . '</p>')
            . '</div><div class="grid2"><div class="card"><h3>Signups — last 30 days</h3><canvas id="c1" height="160"></canvas></div>'
            . '<div class="card"><h3>Purchases &amp; renewals — last 30 days</h3><canvas id="c2" height="160"></canvas></div></div>'
            . $this->chartJs([
                ['c1', array_keys($signups), [['Signups', array_values($signups), '#0A7B5B']]],
                ['c2', array_keys($salesDay), [['New purchases', array_values($salesDay), '#F28C38'], ['Renewals', array_values($renewDay), '#2F5BD1']]],
            ]);
        $this->page('Overview', $html);
    }

    private function users(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $pg = max(1, (int) ($_GET['p'] ?? 1));
        $where = '1=1';
        $args = [];
        if ($q !== '') {
            $where = '(u.email LIKE ? OR u.name LIKE ? OR u.id = ?)';
            $args = ["%$q%", "%$q%", $q];
        }
        $total = $this->count("SELECT COUNT(*) FROM users u WHERE $where", $args);
        $st = $this->db->prepare("SELECT u.*,
              (SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id) devs,
              (SELECT COUNT(*) FROM space_members m WHERE m.user_id = u.id) sps
            FROM users u WHERE $where ORDER BY u.created_at DESC LIMIT 50 OFFSET " . (($pg - 1) * 50));
        $st->execute($args);
        $rows = '';
        foreach ($st->fetchAll() as $u) {
            $rows .= '<tr><td><a href="' . self::BASE . '/users/' . $u['id'] . '">' . $this->e($u['email'] ?: '(no email)') . '</a></td><td>'
                . $this->e($u['name']) . '</td><td>' . $this->method($u) . '</td><td>' . $this->local($u['created_at']) . '</td><td>'
                . $this->local($u['last_login_at']) . '</td><td class="n">' . $u['devs'] . '</td><td class="n">' . $u['sps'] . '</td><td>'
                . ($u['status'] === 'active' ? '<span class="tag ok">active</span>' : '<span class="tag">deleted</span>') . '</td></tr>';
        }
        $html = '<h1>Users <span class="muted small">' . $total . '</span></h1>'
            . '<form class="bar" method="get"><input name="q" value="' . $this->e($q) . '" placeholder="Search email, name or id">'
            . '<button>Search</button><a class="btn2" href="' . self::BASE . '/users.csv">Download CSV</a></form>'
            . '<div class="card"><table><tr><th>Email</th><th>Name</th><th>Sign-in</th><th>Signed up</th><th>Last seen</th><th>Devices</th><th>Spaces</th><th>Status</th></tr>'
            . ($rows ?: '<tr><td colspan="8" class="muted">No users yet.</td></tr>') . '</table></div>' . $this->pager($pg, $total, ['q' => $q]);
        $this->page('Users', $html);
    }

    private function method(array $u): string
    {
        if (!empty($u['apple_sub'])) {
            return 'Apple' . (strpos((string) $u['email'], 'privaterelay.appleid.com') !== false ? ' <span class="muted small">(hidden email)</span>' : '');
        }
        return !empty($u['google_sub']) ? 'Google' : 'Email code';
    }

    private function usersCsv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="users-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'email', 'name', 'sign_in', 'status', 'signed_up', 'last_seen', 'currency']);
        foreach ($this->db->query('SELECT * FROM users ORDER BY created_at DESC') as $u) {
            fputcsv($out, [$u['id'], $u['email'], $u['name'], strip_tags($this->method($u)), $u['status'],
                $this->local($u['created_at'], 'Y-m-d H:i'), $this->local($u['last_login_at'], 'Y-m-d H:i'), $u['currency']]);
        }
        exit;
    }

    private function user(string $id, string $m): void
    {
        $st = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) {
            http_response_code(404);
            $this->page('User', '<p>User not found.</p>');
            return;
        }
        $msg = '';
        if ($m === 'POST' && ($_POST['action'] ?? '') === 'logout') {
            $this->db->prepare('UPDATE users SET token_valid_after = ? WHERE id = ?')->execute([now(), $id]);
            $msg = '<div class="ok">User signed out on all devices.</div>';
        }
        $d = $this->db->prepare('SELECT * FROM devices WHERE user_id = ? ORDER BY updated_at DESC');
        $d->execute([$id]);
        $devs = '';
        foreach ($d->fetchAll() as $r) {
            $devs .= '<tr><td>' . $this->e($r['platform']) . '</td><td>' . $this->e($r['app_version'] ?? '—') . '</td><td>' . $this->e($r['locale'] ?? '—')
                . '</td><td>' . ($r['push_token'] ? 'yes' : 'no') . '</td><td>' . $this->local($r['updated_at']) . '</td></tr>';
        }
        $s = $this->db->prepare('SELECT s.*, m.role, (SELECT COUNT(*) FROM space_members x WHERE x.space_id = s.id) members
            FROM space_members m JOIN spaces s ON s.id = m.space_id WHERE m.user_id = ? ORDER BY s.updated_at DESC');
        $s->execute([$id]);
        $sps = '';
        foreach ($s->fetchAll() as $r) {
            $sps .= '<tr><td>' . $this->e($r['name']) . '</td><td>' . $this->e($r['type']) . '</td><td>' . $this->e($r['role']) . '</td><td class="n">'
                . $r['members'] . '</td><td>' . $this->e($r['currency']) . '</td><td>' . $this->local(gmdate('Y-m-d H:i:s', intdiv((int) $r['updated_at'], 1000))) . '</td></tr>';
        }
        $html = '<p><a href="' . self::BASE . '/users">← Users</a></p><h1>' . $this->e($u['email'] ?: $u['name']) . '</h1>' . $msg
            . '<div class="card"><table class="kv">'
            . '<tr><th>Name</th><td>' . $this->e($u['name']) . '</td></tr><tr><th>Email</th><td>' . $this->e($u['email'] ?? '—') . '</td></tr>'
            . '<tr><th>Sign-in</th><td>' . $this->method($u) . '</td></tr><tr><th>Status</th><td>' . $this->e($u['status']) . '</td></tr>'
            . '<tr><th>Signed up</th><td>' . $this->local($u['created_at']) . '</td></tr><tr><th>Last seen</th><td>' . $this->local($u['last_login_at']) . '</td></tr>'
            . '<tr><th>Currency</th><td>' . $this->e($u['currency']) . '</td></tr><tr><th>User id</th><td class="mono">' . $this->e($u['id']) . '</td></tr></table>'
            . '<form method="post" onsubmit="return confirm(\'Sign this user out on all devices?\')">' . $this->csrf()
            . '<input type="hidden" name="action" value="logout"><button class="btn2">Sign out everywhere</button></form></div>'
            . '<h2>Devices</h2><div class="card"><table><tr><th>Platform</th><th>App version</th><th>Locale</th><th>Push</th><th>Last update</th></tr>'
            . ($devs ?: '<tr><td colspan="5" class="muted">None</td></tr>') . '</table></div>'
            . '<h2>Shared spaces</h2><div class="card"><table><tr><th>Name</th><th>Type</th><th>Role</th><th>Members</th><th>Currency</th><th>Last change</th></tr>'
            . ($sps ?: '<tr><td colspan="6" class="muted">None</td></tr>') . '</table></div>';
        $this->page('User', $html);
    }

    private function purchases(): void
    {
        [$ew, $ea, $env] = $this->envWhere();
        $ew = str_replace(['AND verified = 1', 'verified = 1'], ['', '1=1'], $ew);
        $app = (string) ($_GET['app'] ?? '');
        $type = (string) ($_GET['type'] ?? '');
        $pg = max(1, (int) ($_GET['p'] ?? 1));
        $w = [$ew];
        $args = $ea;
        if ($app !== '') { $w[] = 'bundle_id = ?'; $args[] = $app; }
        if ($type === 'sales') { $w[] = 'notification_type IN ' . self::NEW_SALE; }
        elseif ($type === 'renewals') { $w[] = "notification_type = 'DID_RENEW'"; }
        elseif ($type === 'refunds') { $w[] = "notification_type IN ('REFUND','REVOKE')"; }
        elseif ($type === 'cancels') { $w[] = "(notification_type = 'DID_CHANGE_RENEWAL_STATUS' AND subtype = 'AUTO_RENEW_DISABLED') OR notification_type IN ('EXPIRED','DID_FAIL_TO_RENEW')"; }
        $where = implode(' AND ', array_map(function ($x) { return "($x)"; }, $w));
        $total = $this->count("SELECT COUNT(*) FROM store_events WHERE $where", $args);
        $st = $this->db->prepare("SELECT * FROM store_events WHERE $where ORDER BY created_at DESC, id DESC LIMIT 50 OFFSET " . (($pg - 1) * 50));
        $st->execute($args);
        $rows = '';
        foreach ($st->fetchAll() as $r) {
            $rows .= '<tr><td><a href="' . self::BASE . '/purchases/' . $r['id'] . '">' . $this->local($r['created_at']) . '</a></td><td>'
                . $this->e($this->appName($r['bundle_id'])) . '</td><td>' . $this->e($this->eventLabel($r)) . '</td><td>' . $this->e($r['product_id'] ?? '—')
                . '</td><td class="n">' . $this->money($r['price_milli'] !== null ? (int) $r['price_milli'] : null, $r['currency']) . '</td><td>'
                . $this->e($r['storefront'] ?? '—') . '</td><td>' . $this->e($r['environment'] ?? '—') . '</td><td>'
                . ((int) $r['verified'] ? '<span class="tag ok">verified</span>' : '<span class="tag">unverified</span>') . '</td></tr>';
        }
        $apps = '<option value="">All apps</option>';
        foreach ($this->db->query('SELECT bundle_id, name FROM store_apps ORDER BY COALESCE(name, bundle_id)') as $a) {
            $apps .= '<option value="' . $this->e($a['bundle_id']) . '"' . ($a['bundle_id'] === $app ? ' selected' : '') . '>' . $this->e($a['name'] ?: $a['bundle_id']) . '</option>';
        }
        $types = '';
        foreach (['' => 'All events', 'sales' => 'New purchases', 'renewals' => 'Renewals', 'refunds' => 'Refunds', 'cancels' => 'Cancellations / expiry'] as $k => $v) {
            $types .= '<option value="' . $k . '"' . ($k === $type ? ' selected' : '') . '>' . $v . '</option>';
        }
        $html = '<h1>App Store purchases <span class="muted small">' . $total . '</span></h1>'
            . '<form class="bar" method="get"><select name="env"><option value="Production"' . ($env === 'Production' ? ' selected' : '') . '>Live (App Store)</option>'
            . '<option value="Sandbox"' . ($env === 'Sandbox' ? ' selected' : '') . '>Sandbox / TestFlight</option><option value="all"' . ($env === 'all' ? ' selected' : '') . '>All</option></select>'
            . '<select name="app">' . $apps . '</select><select name="type">' . $types . '</select><button>Filter</button></form>'
            . '<div class="card"><table><tr><th>Time</th><th>App</th><th>Event</th><th>Product</th><th>Price</th><th>Country</th><th>Env</th><th>Apple signature</th></tr>'
            . ($rows ?: '<tr><td colspan="8" class="muted">Nothing yet. Events appear here as soon as Apple sends them.</td></tr>') . '</table></div>'
            . $this->pager($pg, $total, ['env' => $env, 'app' => $app, 'type' => $type]);
        $this->page('Purchases', $html);
    }

    private function purchase(int $id): void
    {
        $st = $this->db->prepare('SELECT * FROM store_events WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            http_response_code(404);
            $this->page('Purchase', '<p>Not found.</p>');
            return;
        }
        $raw = json_decode((string) $r['raw'], true);
        $html = '<p><a href="' . self::BASE . '/purchases">← Purchases</a></p><h1>' . $this->e($this->eventLabel($r)) . '</h1><div class="card"><table class="kv">';
        foreach (['created_at' => 'Received', 'bundle_id' => 'App', 'product_id' => 'Product', 'product_type' => 'Type', 'environment' => 'Environment',
                     'transaction_id' => 'Transaction id', 'original_transaction_id' => 'Original transaction id', 'storefront' => 'Country',
                     'purchase_date' => 'Purchase date', 'expires_date' => 'Expires', 'transaction_reason' => 'Reason', 'app_account_token' => 'App account token'] as $k => $label) {
            $v = in_array($k, ['created_at', 'purchase_date', 'expires_date'], true) ? $this->local($r[$k]) : $this->e((string) ($r[$k] ?? '—'));
            $html .= '<tr><th>' . $label . '</th><td>' . ($v === '' ? '—' : $v) . '</td></tr>';
        }
        $html .= '<tr><th>Price</th><td>' . $this->money($r['price_milli'] !== null ? (int) $r['price_milli'] : null, $r['currency']) . '</td></tr></table></div>'
            . '<h2>Raw data from Apple</h2><pre class="card">' . $this->e(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        $this->page('Purchase', $html);
    }

    private function apps(string $m): void
    {
        if ($m === 'POST' && isset($_POST['bundle_id'])) {
            $this->db->prepare('UPDATE store_apps SET name = ? WHERE bundle_id = ?')
                ->execute([trim((string) ($_POST['name'] ?? '')) ?: null, (string) $_POST['bundle_id']]);
        }
        [$ew, $ea, $env] = $this->envWhere();
        $d30 = $this->localDayStartUtc(29);
        $today = $this->localDayStartUtc(0);
        $rows = '';
        foreach ($this->db->query('SELECT * FROM store_apps ORDER BY last_seen DESC')->fetchAll() as $a) {
            $b = $a['bundle_id'];
            $c = function (string $cond, string $from) use ($b, $ew, $ea): int {
                return $this->count("SELECT COUNT(*) FROM store_events WHERE bundle_id = ? AND created_at >= ? AND $cond AND $ew", array_merge([$b, $from], $ea));
            };
            $rows .= '<tr><td><form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="bundle_id" value="' . $this->e($b) . '">'
                . '<input name="name" value="' . $this->e($a['name'] ?? '') . '" placeholder="Name"><button class="btn2">Save</button></form></td><td class="mono">'
                . $this->e($b) . '</td><td class="n">' . $c('notification_type IN ' . self::NEW_SALE, $today) . '</td><td class="n">'
                . $c('notification_type IN ' . self::NEW_SALE, $d30) . '</td><td class="n">' . $c("notification_type = 'DID_RENEW'", $d30) . '</td><td class="n">'
                . $c("notification_type = 'REFUND'", $d30) . '</td><td>' . $this->local($a['last_seen']) . '</td><td><a href="' . self::BASE . '/purchases?app=' . urlencode($b) . '&env=' . $env . '">events →</a></td></tr>';
        }
        $html = '<h1>Apps</h1><p class="muted">Apps appear here automatically the first time Apple sends a notification for them. Give them a friendly name.</p>'
            . $this->envTabs($env)
            . '<div class="card"><table><tr><th>Name</th><th>Bundle id</th><th>Purchases today</th><th>Purchases 30d</th><th>Renewals 30d</th><th>Refunds 30d</th><th>Last event</th><th></th></tr>'
            . ($rows ?: '<tr><td colspan="8" class="muted">No apps yet — waiting for the first notification from Apple.</td></tr>') . '</table></div>';
        $this->page('Apps', $html);
    }

    private function spaces(): void
    {
        $pg = max(1, (int) ($_GET['p'] ?? 1));
        $total = $this->count('SELECT COUNT(*) FROM spaces');
        $st = $this->db->query('SELECT s.*, u.email owner_email,
              (SELECT COUNT(*) FROM space_members m WHERE m.space_id = s.id) members,
              (SELECT COUNT(*) FROM sp_transactions t WHERE t.space_id = s.id AND t.deleted = 0) txs
            FROM spaces s LEFT JOIN users u ON u.id = s.owner_id ORDER BY s.updated_at DESC LIMIT 50 OFFSET ' . (($pg - 1) * 50));
        $rows = '';
        foreach ($st->fetchAll() as $r) {
            $rows .= '<tr><td>' . $this->e($r['name']) . '</td><td>' . $this->e($r['type']) . '</td><td>' . $this->e($r['owner_email'] ?? '—') . '</td><td class="n">'
                . $r['members'] . '</td><td class="n">' . $r['txs'] . '</td><td>' . $this->e($r['currency']) . '</td><td>'
                . ((int) $r['archived'] ? 'archived' : 'active') . '</td><td>' . $this->local(gmdate('Y-m-d H:i:s', intdiv((int) $r['created_at'], 1000))) . '</td><td>'
                . $this->local(gmdate('Y-m-d H:i:s', intdiv((int) $r['updated_at'], 1000))) . '</td></tr>';
        }
        $this->page('Spaces', '<h1>Shared spaces <span class="muted small">' . $total . '</span></h1><div class="card"><table><tr><th>Name</th><th>Type</th><th>Owner</th><th>Members</th><th>Transactions</th><th>Currency</th><th>Status</th><th>Created</th><th>Last change</th></tr>'
            . ($rows ?: '<tr><td colspan="9" class="muted">No spaces yet.</td></tr>') . '</table></div>' . $this->pager($pg, $total, []));
    }

    private function ai(): void
    {
        $st = $this->db->prepare('SELECT day, kind, SUM(count) c, SUM(tokens) t, COUNT(DISTINCT user_id) users FROM ai_usage WHERE day >= ? GROUP BY day, kind ORDER BY day DESC, kind');
        $st->execute([gmdate('Y-m-d', time() - 30 * 86400)]);
        $rows = '';
        foreach ($st->fetchAll() as $r) {
            $rows .= '<tr><td>' . $this->e($r['day']) . '</td><td>' . $this->e($r['kind']) . '</td><td class="n">' . $r['c'] . '</td><td class="n">' . number_format((int) $r['t']) . '</td><td class="n">' . $r['users'] . '</td></tr>';
        }
        $this->page('AI usage', '<h1>AI usage — last 30 days</h1><div class="card"><table><tr><th>Day (UTC)</th><th>Feature</th><th>Calls</th><th>Tokens</th><th>Users</th></tr>'
            . ($rows ?: '<tr><td colspan="5" class="muted">No AI usage yet.</td></tr>') . '</table></div>');
    }

    // ------------------------------------------------------------------ view helpers

    private function pager(int $pg, int $total, array $q): string
    {
        $pages = (int) ceil($total / 50);
        if ($pages <= 1) {
            return '';
        }
        $link = function (int $p, string $label) use ($q) {
            return '<a class="chip" href="?' . http_build_query(array_merge($q, ['p' => $p])) . '">' . $label . '</a>';
        };
        return '<div class="chips">' . ($pg > 1 ? $link($pg - 1, '← Prev') : '') . '<span class="muted">Page ' . $pg . ' / ' . $pages . '</span>'
            . ($pg < $pages ? $link($pg + 1, 'Next →') : '') . '</div>';
    }

    /** Server-side SVG bar charts (no external scripts). */
    private function chartJs(array $charts): string
    {
        $out = '';
        foreach ($charts as [$id, $labels, $sets]) {
            $n = count($labels);
            $max = 1;
            foreach ($labels as $i => $_) {
                $sum = 0;
                foreach ($sets as $set) { $sum += (int) $set[1][$i]; }
                $max = max($max, $sum);
            }
            $w = 600; $h = 190; $pad = 22; $bw = ($w - $pad) / max(1, $n);
            $svg = '';
            for ($g = 0; $g <= 2; $g++) {
                $y = 8 + ($h - 30) * $g / 2;
                $val = (int) round($max * (2 - $g) / 2);
                $svg .= '<line x1="' . $pad . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="#E6EBE9"/>'
                    . '<text x="0" y="' . ($y + 4) . '" font-size="10" fill="#6B7773">' . $val . '</text>';
            }
            foreach ($labels as $i => $d) {
                $x = $pad + $i * $bw + $bw * .15;
                $base = $h - 22;
                $tip = substr($d, 8, 2) . '/' . substr($d, 5, 2);
                foreach ($sets as [$name, $data, $color]) {
                    $v = (int) $data[$i];
                    if ($v <= 0) { continue; }
                    $bh = ($h - 30) * $v / $max;
                    $base -= $bh;
                    $svg .= '<rect x="' . round($x, 1) . '" y="' . round($base, 1) . '" width="' . round($bw * .7, 1) . '" height="' . round($bh, 1)
                        . '" rx="2" fill="' . $color . '"><title>' . $this->e("$tip · $name: $v") . '</title></rect>';
                }
                if ($i % 5 === 0 || $i === $n - 1) {
                    $svg .= '<text x="' . round($x + $bw * .35, 1) . '" y="' . ($h - 6) . '" font-size="10" text-anchor="' . ($i === $n - 1 ? 'end' : 'middle') . '" fill="#6B7773">' . $tip . '</text>';
                }
            }
            $legend = '';
            if (count($sets) > 1) {
                foreach ($sets as [$name, , $color]) {
                    $legend .= '<span class="lg"><i style="background:' . $color . '"></i>' . $this->e($name) . '</span>';
                }
            }
            $out .= '<script>void 0</script><template id="t-' . $id . '"></template>'
                . '<div id="svg-' . $id . '" data-for="' . $id . '">' . $legend . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img">' . $svg . '</svg></div>';
        }
        // move each chart into its card (replaces the <canvas> placeholder)
        return $out . '<script>document.querySelectorAll("[data-for]").forEach(function(d){var c=document.getElementById(d.dataset.for);if(c){c.replaceWith(d);}});</script>';
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function redirect(string $to): void
    {
        header('Location: ' . self::BASE . ($to === '/' ? '' : $to));
        exit;
    }

    private function page(string $title, string $body, bool $nav = true): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $links = '';
        if ($nav) {
            foreach (['' => 'Overview', '/users' => 'Users', '/purchases' => 'Purchases', '/apps' => 'Apps', '/spaces' => 'Spaces', '/ai' => 'AI usage', '/account' => 'Account'] as $k => $v) {
                $on = ($this->path === ($k ?: '/')) || ($k !== '' && strpos($this->path, $k) === 0);
                $links .= '<a' . ($on ? ' class="on"' : '') . ' href="' . self::BASE . $k . '">' . $v . '</a>';
            }
            $links .= '<a href="' . self::BASE . '/logout" class="out">Sign out</a>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $this->e($title) . ' · Admin</title><style>
:root{--g:#0A7B5B;--ink:#0F1F1A;--mut:#6B7773;--bg:#F4F6F5;--card:#fff;--line:#E6EBE9}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}
nav{background:#0D5F47;padding:0 18px;display:flex;gap:4px;flex-wrap:wrap;align-items:center}nav b{color:#fff;margin-right:14px;padding:14px 0}
nav a{color:#BDEBDB;text-decoration:none;padding:14px 10px}nav a.on{color:#fff;border-bottom:3px solid #F28C38}nav a.out{margin-left:auto}
main{max-width:1180px;margin:0 auto;padding:18px}h1{font-size:24px;margin:8px 0 16px}h2{font-size:17px;margin:26px 0 10px}h3{margin:0 0 10px;font-size:15px}
.muted{color:var(--mut)}.small{font-size:13px;font-weight:400}.mono{font-family:ui-monospace,Menlo,monospace;font-size:12.5px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px;overflow-x:auto}.narrow{max-width:420px}
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:12px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px}.kl{color:var(--mut);font-size:12.5px}.kv{font-size:24px;font-weight:800;margin-top:2px}.kv.sm{font-size:15px;font-weight:700;line-height:1.5}.lg{display:inline-flex;align-items:center;gap:6px;margin-right:14px;font-size:12.5px;color:var(--mut)}.lg i{width:10px;height:10px;border-radius:3px;display:inline-block}.ks{color:var(--mut);font-size:12px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:820px){.grid2{grid-template-columns:1fr}}
table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--mut);font-weight:600;font-size:12.5px}
td.n{text-align:right}table.kv th{width:200px}a{color:var(--g)}
.tag{display:inline-block;line-height:1.5;padding:2px 8px;border-radius:10px;background:#EDEFF2;font-size:12px}.tag.ok{background:#D9F1E6;color:var(--g)}
.chips{display:flex;gap:8px;align-items:center;margin:8px 0 12px;flex-wrap:wrap}.chip{padding:6px 12px;border-radius:14px;background:#E3E9E6;color:var(--ink);text-decoration:none}.chip.on{background:var(--g);color:#fff}
.bar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}input,select{padding:9px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:#fff}
.bar input{flex:1;min-width:220px}button,.btn2{padding:9px 16px;border:0;border-radius:10px;background:var(--g);color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.btn2{background:#E3E9E6;color:var(--ink)}form.inline{display:flex;gap:6px}
label{display:block;margin:10px 0;font-weight:600}label input{display:block;width:100%;margin-top:4px;font-weight:400}
.auth{max-width:380px;margin:9vh auto;background:#fff;border:1px solid var(--line);border-radius:20px;padding:26px}.auth button{width:100%;margin-top:8px}
.err{background:#FDE8E8;color:#B42318;padding:10px 12px;border-radius:10px;margin-bottom:10px}.ok{background:#D9F1E6;color:var(--g);padding:10px 12px;border-radius:10px;margin-bottom:10px}
pre{white-space:pre-wrap;font-size:12px}
</style></head><body>' . ($nav ? '<nav><b>Expense Manager · Admin</b>' . $links . '</nav>' : '') . '<main>' . $body . '</main></body></html>';
        exit;
    }
}
