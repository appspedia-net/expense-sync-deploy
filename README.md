# Expense Manager v3 — sync API (`sync.imrashid.net`)

Plain PHP 7.4+ / PDO MySQL backend for **shared spaces** (family, trip, flatmates…).
Personal data never touches this server — only spaces, their members and the rows
inside them. The app is offline-first; this is a relay with delta sync.

No Composer, no framework. Spec: [`docs/V3_SPEC.md`](../docs/V3_SPEC.md) §1.

```
server/
  public/                 ← document root
    index.php             front controller: middleware + route table
    .htaccess             rewrite everything to index.php, block _private & dotfiles
    .user.ini             PHP upload limits (FastCGI)
  app/
    bootstrap.php         config, autoload, PDO, helpers json()/fail()/input()/auth()/now()/uuid()
    Router.php            tiny router with {param} placeholders
    Auth.php              HS256 JWT (self-contained), X-App-Key, user upsert
    Controllers/          Health, Auth, Me, Spaces, Members, Invites, Sync, SpaceData, Rates, Ai, Admin
    Services/             AppleAuth, GoogleAuth, Mail, Push (APNs), Sync, Balances, Activity, Fx, OpenAi, RateLimit, Http
  _private/               ← NOT web-reachable: config.php, logs/, cache/, .p8 keys
  schema.sql              full schema, idempotent
  config.example.php      every config key, documented
  tests/smoke.sh          curl smoke test
```

---

## 1. Deploy on 20i (shared hosting, PHP 7.4+)

Target: `/home/sites/19b/6/6250f87ffa/sync.imrashid.net/` with document root `…/sync.imrashid.net/public`.

1. **PHP version** — My20i → Manage Hosting → *sync.imrashid.net* → **PHP Configuration**: pick PHP 7.4 or newer
   (8.x works too). Make sure `curl`, `openssl`, `pdo_mysql`, `mbstring` and `json` are on (they are by default).

2. **Document root** — the web root must be the `public/` folder, with `app/` and `_private/` as its siblings
   (`index.php` locates them via `dirname(__DIR__)`). If 20i serves the subdomain from a folder named
   `public_html` instead and you cannot change the document root, just rename `public` → `public_html`
   (the folder name is not used anywhere in the code). The required shape is:

   ```
   sync.imrashid.net/
     public/        (or public_html)  ← document root: index.php, .htaccess, .user.ini
     app/
     _private/      config.php, logs/, cache/, *.p8
     schema.sql, config.example.php, README.md, tests/
   ```

3. **Upload** — via FTP/SFTP (My20i → *FTP Accounts*) or *File Manager*: upload the whole `server/` folder's
   contents into `sync.imrashid.net/`. Do **not** upload your local `_private/config.php` by accident; create it on
   the server (next step).

4. **Create the database** — My20i → **MySQL Databases** → create a database + user, note host/name/user/password.

5. **Import the schema** — My20i → **phpMyAdmin** → select the database → *Import* → choose `schema.sql` → Go.
   (Idempotent: safe to re-run after updates.)

6. **Config** — in File Manager copy `config.example.php` to `_private/config.php` and fill in:
   `db.*`, `app_key`, `jwt_secret`, `admin_token`, `apple.bundle_ids`, `google_client_ids`, `apns.*`,
   `mail.*`, `ai.openai_key`. Generate secrets with
   `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` (or any password generator, 48+ chars).

7. **Keys** — upload the APNs `.p8` (Apple Developer → Keys → *Apple Push Notifications service*) into
   `_private/` and point `apns.p8_path` at it. Never put it under `public/`.

8. **Permissions** — `_private/logs/` and `_private/cache/` must be writable by PHP (20i runs PHP as your user,
   so `755`/`775` is enough; if logs do not appear try `777` on those two folders only). `_private/config.php`: `600` or `640`.

9. **Test**

   ```bash
   curl -s https://sync.imrashid.net/v1/health
   # {"ok":true,"time":"…","db":true,"version":"3.0.0","php":"7.4.33"}
   ```

   `db:false` → wrong DB credentials in `_private/config.php` (see `_private/logs/error-*.log`).
   A 500 with `config_missing` → `_private/config.php` not found in the right place.

10. **Mail** — OTP codes are sent with PHP `mail()` through 20i's MTA. Set `mail.from` to an address on a domain
    hosted in the same 20i account (e.g. `no-reply@imrashid.net`) and add 20i's SPF include to that domain's DNS
    so codes do not land in spam. Every attempt is logged in `_private/logs/mail-*.log`.

11. **Universal links** — on **imrashid.net** (not here) serve `/j/{code}` as a landing page and
    `/.well-known/apple-app-site-association` with the team id + bundle (see spec §1 Misc).

### Updating
Re-upload `app/` and `public/`; re-import `schema.sql` if it changed (it only adds tables). `_private/` is never overwritten.

---

## 2. API overview

All responses are JSON (UTF-8). Errors: `{"error":{"code":"…","message":"…"}}` with a proper HTTP status.
Headers on every request: `X-App-Key: <app_key>`; on authenticated routes `Authorization: Bearer <jwt>` (30-day token, re-login to refresh).
`GET /v1/health` is the only route that skips the app key.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/v1/health` | – | `{ok,time,db,version}` |
| POST | `/v1/auth/apple` | – | `{identity_token, full_name?}` → `{token,user,is_new}` |
| POST | `/v1/auth/google` | – | `{id_token}` → token |
| POST | `/v1/auth/otp/request` | – | `{email}` → mails a 6-digit code (10 min, 5/hour/e-mail) |
| POST | `/v1/auth/otp/verify` | – | `{email, code}` → token |
| GET / PATCH | `/v1/me` | user | profile; PATCH `{name, avatar_color, currency}` |
| POST | `/v1/me/device` | user | `{platform, push_token, app_version, device_id?, locale?}` |
| DELETE | `/v1/me` | user | account deletion (anonymise, leave spaces, hand over ownership) |
| POST / GET | `/v1/spaces` | user | create `{name,type,currency,icon,color,share_mode,id?}` / list (`?archived=0|1|all`) |
| GET / PATCH / DELETE | `/v1/spaces/{id}` | member / editor / owner | show / update / hard delete |
| POST | `/v1/spaces/{id}/leave` | member | leave (owner hands over automatically; last member deletes the space) |
| POST | `/v1/spaces/{id}/archive` | owner | `{archived?: bool}` (default true) |
| GET | `/v1/spaces/{id}/members` | member | list |
| PATCH / DELETE | `/v1/spaces/{id}/members/{uid}` | owner | `{role}` (role=owner transfers) / remove |
| POST / GET | `/v1/spaces/{id}/invites` | editor | create `{role, expires_h, max_uses}` → `{code,url}` / list active |
| DELETE | `/v1/spaces/{id}/invites/{code}` | editor | revoke |
| GET | `/v1/invites/{code}` | – | public preview (space name/type/member count/inviter) |
| POST | `/v1/invites/{code}/accept` | user | join |
| GET | `/v1/spaces/{id}/changes?since=&limit=&entities=` | member | delta pull → `{changes:[{entity,row}], next_since, has_more}` |
| POST | `/v1/spaces/{id}/changes` | editor | `{changes:[{entity, op, row, base_version?}]}` → `{applied, conflicts, errors}` |
| GET | `/v1/spaces/{id}/balances` | member | per-member paid/share/settled/net + `simplified_debts` |
| GET | `/v1/spaces/{id}/activity?since=` | member | feed |
| GET | `/v1/rates?base=USD` | – | FX table (cached 24h) |
| POST | `/v1/ai/receipt` | user + `X-Pro-Receipt` | multipart `image` or `{image_base64}` → `{receipt:{amount,currency,merchant,date,category,items[]}}` |
| POST | `/v1/ai/ask` | user + `X-Pro-Receipt` | `{question, context?, locale?}` → `{answer}` |
| GET | `/v1/admin/stats` | `X-Admin-Token` | counters |

**Sync entities**: `transactions`, `categories`, `budgets`, `settlements`, `comments`, `recurring_rules`.
Every row carries `id` (client uuid), `space_id`, `created_by`, `updated_by`, `updated_at` (ms, server), `client_updated_at` (ms, client),
`version`, `deleted`. Conflict rule: last-write-wins by `client_updated_at`; an older incoming row is returned in
`conflicts[]` with the current `server_row` (the client should overwrite its local copy). `base_version` is optional
and only informs the conflict reason. Deletes are tombstones (`deleted=1`) so other devices pick them up.

**Balances**: `fx_rate` on a transaction = value of 1 unit of its currency in the space currency.
`net = paid − share + settled`; positive means the member is owed money. `simplified_debts` is a greedy
largest-debtor→largest-creditor matching (≤ n−1 transfers).

**Push**: after a successful change push, every other member's iOS devices get one APNs alert
(`"{name} added 12.50 USD – Dinner"` or `"{name} made N changes"`), plus alerts for join/leave/role/archive/delete.
Missing APNs config just logs to `_private/logs/push-*.log`.

---

## 3. Smoke test (curl)

```bash
export BASE=https://sync.imrashid.net KEY=<app_key>

# health (no key needed)
curl -s $BASE/v1/health

# request a login code (check the inbox / _private/logs/mail-*.log)
curl -s -X POST $BASE/v1/auth/otp/request -H "X-App-Key: $KEY" -H 'Content-Type: application/json' -d '{"email":"you@example.com"}'

# verify → token
curl -s -X POST $BASE/v1/auth/otp/verify -H "X-App-Key: $KEY" -H 'Content-Type: application/json' -d '{"email":"you@example.com","code":"123456"}'
export TOKEN=<token from above>

# me
curl -s $BASE/v1/me -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN"

# create a space
curl -s -X POST $BASE/v1/spaces -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Goa trip","type":"trip","currency":"INR","share_mode":"all"}'
export SID=<space id>

# invite + preview
curl -s -X POST $BASE/v1/spaces/$SID/invites -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"role":"editor"}'
curl -s $BASE/v1/invites/ABC123 -H "X-App-Key: $KEY"

# push a transaction
curl -s -X POST $BASE/v1/spaces/$SID/changes -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"changes":[{"entity":"transactions","op":"upsert","row":{"id":"7c1e…","type":"expense","amount":3000,"currency":"INR","title":"Dinner","date":"2026-10-20","split_type":"equal","client_updated_at":1792944242000}}]}'

# pull, balances, activity
curl -s "$BASE/v1/spaces/$SID/changes?since=0" -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN"
curl -s  $BASE/v1/spaces/$SID/balances       -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/v1/spaces/$SID/activity?since=0" -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN"

# FX, AI (Pro), admin
curl -s "$BASE/v1/rates?base=PKR" -H "X-App-Key: $KEY"
curl -s -X POST $BASE/v1/ai/receipt -H "X-App-Key: $KEY" -H "Authorization: Bearer $TOKEN" -H "X-Pro-Receipt: <appstore receipt>" -F image=@receipt.jpg
curl -s $BASE/v1/admin/stats -H "X-App-Key: $KEY" -H "X-Admin-Token: <admin_token>"
```

Or run everything at once:

```bash
cd /path/to/expenses-manager && BASE_URL=https://sync.imrashid.net APP_KEY=<app_key> TOKEN=<jwt> ADMIN_TOKEN=<admin_token> bash server/tests/smoke.sh
```

---

## 4. Local development

```bash
cd /path/to/expenses-manager/server
cp config.example.php _private/config.php   # edit db.* (any MySQL) or use 'db' => ['dsn' => 'sqlite:/tmp/dev.sqlite'] with a translated schema
php -S 127.0.0.1:8089 -t public public/index.php
BASE_URL=http://127.0.0.1:8089 APP_KEY=<app_key> bash tests/smoke.sh
```

With `debug => true` and `auth.otp_debug_return_code => true` the OTP request response includes `debug_code`
so you can log in without e-mail. Never enable either in production.

## 5. Security notes

* Secrets live only in `_private/config.php` (gitignored). `_private/` carries a deny-all `.htaccess` and sits outside the document root.
* Apple identity tokens are verified locally (RS256 against Apple's JWKS, `iss`/`aud`/`exp` checked). Google tokens via Google's tokeninfo endpoint with `aud` check.
* OTP codes are stored as HMAC hashes, expire after 10 minutes, 5 wrong attempts lock the code, 5 requests/hour/e-mail.
* JWTs are HS256 with a server secret; account deletion sets `token_valid_after` so old tokens die immediately.
* Every write is role-checked (`viewer` can only read); rows are scoped to the space in every query.
* Rate limits: per-IP global, per-IP/per-e-mail auth, per-user daily AI limits (`rate_limits` + `ai_usage`).
