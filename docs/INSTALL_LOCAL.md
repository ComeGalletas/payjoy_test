# Local install

This guide takes a clean checkout to a working `/convert`, the chat widget and a report with demo
data on one machine, **without Docker** (ADR 0005: the local install is the only path in Phases 1
and 2; Docker arrives in P3-01).

Commands are run **from the repository root**. They are the same in PowerShell (Windows) and in
a Linux or macOS shell unless a block says otherwise. The repository path may contain spaces, so
keep the quotes around paths.

## 1. Prerequisites

- **PHP 8.3** (the lock file targets 8.3.33) with the extensions `pdo_sqlite`, `curl`, `mbstring`
  and `openssl` (`api/composer.json` requires all four). Enable `zip` too, so Composer can unpack
  packages.
- **Composer 2.**
- **git.**
- Optional: **GNU Make** and **Node**, for contributors (section 13).

Check them:

```
php -v
php -m
composer --version
```

`php -m` must list `curl`, `mbstring`, `openssl` and `pdo_sqlite`. `php --ini` shows which
`php.ini` is loaded; on Windows the PHP package usually needs a `php.ini` copied from
`php.ini-development` with those `extension=` lines uncommented.

### The CA bundle

Every call to ExchangeRate-API is HTTPS. PHP on Windows ships without a CA bundle, so without one
every upstream call fails (the API answers `UPSTREAM_TIMEOUT`). Point both settings in `php.ini` at
a CA bundle file:

```
curl.cainfo = "C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt"
openssl.cafile = "C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt"
```

That path is the bundle Git for Windows installs (it is updated only when Git is). Any current
bundle works, for example curl's `cacert.pem`. On Linux and macOS the system bundle is normally
found without these settings; if not, set them to it (`/etc/ssl/certs/ca-certificates.crt` on
Debian and Ubuntu). Check that HTTPS works:

```
php -r "echo file_get_contents('https://example.com') ? 'ok' : 'fail';"
```

### `php.ini` settings for the server

Set these in the `php.ini` the server uses:

```
expose_php = Off
display_errors = Off
log_errors = On
```

- `display_errors = Off`: with it on, a PHP startup warning (an oversized POST, for example) is
  printed as an HTML page with status 200 before the app runs. The app turns display off itself
  once it runs, and section 7 also passes `-d display_errors=0`.
- `expose_php = Off`: PHP adds `X-Powered-By` before the app runs; the app removes it from every
  answer, and this stops PHP sending it at all.

**Don't disable `ini_set`.** Some hardened hosts list it in `disable_functions`. The app calls
`ini_set()` at startup (`serialize_precision = -1`) and when it sends an answer without a body,
and `ScaledRate::fromRate()` needs it to read a rate's shortest decimal digits. Check:

```
php -r "echo function_exists('ini_set') ? 'ok' : 'ini_set is disabled';"
```

The test suite runs with `curl_exec` and `curl_multi_exec` disabled and `allow_url_fopen=0`, so it
can't reach the network (ADR 0018). Don't copy those flags into the server's `php.ini`: the real
upstream client needs curl (or `allow_url_fopen`).

## 2. Get the code

```
git clone https://github.com/ComeGalletas/payjoy_test.git "fx-installments"
cd "fx-installments"
```

## 3. Configure `.env`

The app reads secrets and connection settings from `.env` at the repository root. Copy the
template and fill it in; `.env` is git-ignored and must never be committed.

PowerShell:

```
Copy-Item .env.example .env
```

Linux and macOS:

```
cp .env.example .env
```

Edit `.env` with any editor and save it as UTF-8 without a BOM. The keys:

| Key | Meaning |
|---|---|
| `EXCHANGERATE_API_KEY` | Your ExchangeRate-API key. Server-side only: it sits in the upstream URL path, and the app never shows it. Letters, digits, `-` and `_`; empty means every rate lookup fails with `UPSTREAM_AUTH` (reason `no_key`) without calling upstream. |
| `ADMIN_TOKEN` | The bearer token for `/reports` and the admin view of `/health`. **Visible ASCII only** (characters `!` to `~`, no spaces), because the `Authorization: Bearer <token>` header is parsed strictly. Empty means every admin request is refused (401). |
| `DB_DSN` | The PDO DSN. Empty means the SQLite file `api/var/app.db`. A relative SQLite path is read from the repository root, so `sqlite:api/var/app.db` is the same file. |
| `DB_USER`, `DB_PASS` | Database credentials; leave them empty for SQLite. |
| `TRUSTED_PROXY` | The one peer address whose `X-Forwarded-For` is believed. Empty when no proxy is in front (section 9). |
| `DATA_ROOT`, `BACKUP_ROOT` | Placeholders for protected folders; Phase 1 doesn't use them. |

To make an admin token:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

A variable already set in the environment wins over `.env` (the file never overrides one).

## 4. Install the dependencies

```
composer install --working-dir=api
```

On a machine that won't run the tests you may add `--no-dev`.

## 5. Create the database: `migrate.php`

The app **never changes the schema itself** (ADR 0019). `bin/migrate.php` creates the database
and applies every pending migration:

```
php api/bin/migrate.php
```

On a new checkout it prints:

```
migrate: applied 1 p1_01_baseline
migrate: applied 2 phase1_tables
migrate: applied 3 events_amount_usd_cents
migrate: schema version 0 -> 3
```

and on a database that is already current, `migrate: schema is current (version 3)`. It exits 0
when done, 1 on failure (`migrate: failed: <exception class>: <message>` on stderr) and 2 for
usage errors.

**Run it after `composer install` and after every `git pull`**, before starting the server:

- **One migrate at a time.** Two runs at once are not coordinated.
- **Back up `api/var/app.db` first.** Stop the server and any CLI first, so SQLite has folded its
  write-ahead log into the file; if an `app.db-wal` file is still there, copy it too.

PowerShell:

```
Copy-Item api/var/app.db "api/var/app.db.bak-$(Get-Date -Format yyyyMMdd-HHmmss)"
```

Linux and macOS:

```
cp api/var/app.db "api/var/app.db.bak-$(date +%Y%m%d-%H%M%S)"
```

- **A database behind the code fails closed.** Until migrate runs, every request answers 500
  `INTERNAL`, and the server's error log (the `php -S` terminal) shows
  `fx-installments INTERNAL before routing: FxInstallments\Db\SchemaOutOfDate at …`. That line
  means **run migrate**. The CLIs refuse too (`report: failed: FxInstallments\Db\SchemaOutOfDate`).
  `GET /health` is the one request that still answers: 503 `UNHEALTHY` (section 10).
- A database **ahead** of the code (after a code rollback) keeps working, unless a migration in
  between renamed or dropped something, like migration 3 (`amount_usd` became
  `amount_usd_cents`). Rolling back past such a migration needs the backup.

### Repairing `SchemaCorrupt`

The schema version lives in the `app_state` row `schema_version`. If that row is missing or isn't
a whole number, the database counts as corrupt: the app answers 500 to everything (and
`GET /health` 503), and `migrate.php`, `--prune` and every CLI exit 1:

```
migrate: failed: FxInstallments\Db\SchemaCorrupt: app_state.schema_version is not a non-negative integer
```

(or `app_state exists but has no schema_version row`). Nothing guesses a version. To repair:

1. Stop the server and copy the database file as it is.
2. **Restore the backup** taken before the last migrate, if you have one, and run migrate again.
3. Otherwise, check which schema the file really has, with the `sqlite3` tool, and set the row to
   that version. Version 3 has the seven tables `app_state`, `events`, `ip_blocks`, `rate_cache`,
   `rate_limits`, `requests` and `sessions`, and `events` has an `amount_usd_cents` column
   (`api/db/schema.sql` is the full version 3 schema):

```
sqlite3 api/var/app.db ".tables"
sqlite3 api/var/app.db "PRAGMA table_info(events);"
sqlite3 api/var/app.db "SELECT key, value FROM app_state;"
sqlite3 api/var/app.db "UPDATE app_state SET value = '3' WHERE key = 'schema_version';"
```

   If the row is missing, insert it instead:

```
sqlite3 api/var/app.db "INSERT INTO app_state (key, value, updated_at) VALUES ('schema_version', '3', CAST(strftime('%s', 'now') AS INTEGER));"
```

   Never set a version higher than the schema shows: migrate would skip the missing parts. A
   version that is too low is safe, because the later migrations then fail loudly and roll back.
4. `php api/bin/migrate.php` must then print `migrate: schema is current (version 3)`.

### A daily `--prune` job

```
php api/bin/migrate.php --prune
```

It deletes rate-limit windows older than a day, closed sessions older than 30 days, request-log
rows older than `requests.retention_days` (30) and IP blocks that ended at least
`block.window_minutes` ago, and prints one line per table:

```
prune: rate_limits 0 deleted
prune: sessions 0 deleted
prune: requests 0 deleted
prune: ip_blocks 0 deleted
```

`events` are never deleted. It refuses (exit 1) a database behind the code. Without this job the
request log, with its raw IPs, grows forever (see the privacy note in [README.md](README.md)).
Run it once a day at a quiet hour: while it holds SQLite's write lock for more than 5 s,
`/health` reads as unhealthy. The script finds `.env` and the database from its own location, so
the job can start in any folder.

Windows (Task Scheduler; use your own paths to `php.exe` and the checkout):

```
schtasks /Create /SC DAILY /ST 03:30 /TN "fx-installments prune" /TR "\"C:\php\php.exe\" \"D:\path with spaces\fx-installments\api\bin\migrate.php\" --prune"
```

Linux and macOS (`crontab -e`):

```
30 3 * * * php "/path/to/fx-installments/api/bin/migrate.php" --prune
```

## 6. Demo data: `seed_demo.php`

To see the reports filled before any real traffic, seed demo data **after migrate**, on the new
database:

```
php api/db/seed_demo.php
```

It prints a line like
`seed_demo: wrote 14 sessions, 92 events and 97 request-log rows, 2026-10-16 to 2026-10-22
(America/Bogota)` and writes, in one transaction:

- 14 bot sessions on the `static` and `cli` channels, spread over the default report period (the
  last 7 days in `reports.timezone`): completed, abandoned at several steps, never answered, and
  two still open, which expire within 30 minutes of seeding (`bot.session_ttl_minutes`);
- seven `/convert` requests, a few error responses and one IP that was rate-limited, blocked and
  unblocked;
- the matching request-log rows, from documentation addresses only (192.0.2.0/24,
  198.51.100.0/24, 203.0.113.0/24).

Amounts are integer cents. It writes **no rates** (nothing in `rate_cache`, the breaker or the
quota cache), so your first real conversion still makes one real `/latest/USD` call. Every demo
event has `"demo": true` in its meta.

It refuses (exit 1) a database that already has events or sessions, so it never mixes demo rows
into real ones and never runs twice. There is no undo, because events are never pruned: for a
database without demo data, stop the server, move `api/var/app.db` (and any `app.db-wal` and
`app.db-shm`) away, and run migrate again. Exit codes: 0 done, 1 refused or failed, 2 usage.

## 7. Start the server

```
php -d display_errors=0 -S 127.0.0.1:8080 -t api/public api/public/index.php
```

`api/public/index.php` is the single entry point; every request goes through it. Keep the
terminal open: PHP's development server writes the app's error log lines there. Bind it to
`127.0.0.1`, as above, unless a proxy on the same machine forwards to it (section 9). Phase 3
replaces it with nginx and php-fpm (P3-01).

## 8. Try it

In a second terminal. On Windows PowerShell 5.1, type `curl.exe`, because `curl` is an alias for
`Invoke-WebRequest` there; on Linux and macOS, type `curl`.

The health check:

```
curl.exe "http://127.0.0.1:8080/health"
```

answers `{"ok":true,"data":{"status":"ok"}}`.

A conversion, with your real key (one `/latest/USD` call refreshes every rate; later conversions
in the same refresh window make none):

```
curl.exe "http://127.0.0.1:8080/convert?amount=200&cy=BRL&installments=3"
```

Linux and macOS:

```
curl "http://127.0.0.1:8080/convert?amount=200&cy=BRL&installments=3"
```

The numbers depend on today's rate. At a rate of 5.2291 (the test suite's mocked upstream) the
answer is:

```
{"ok":true,"data":{"amount_usd":200,"currency":"BRL","rate":5.2291,"converted":1045.82,"installments":3,"installment_amount":348.6066666666666,"rate_as_of":"2026-10-21T00:00:01Z","stale":false,"display":{"amount_usd":"200.00","rate":"5.2291","converted":"1,045.82","installment":"348.60","last_installment":"348.62","regular_count":2,"even":false,"rate_as_of":"2026-10-21 00:00 UTC"}}}
```

Show the `display` strings as they are; never round the raw numbers yourself (see the money rule
in [README.md](README.md)).

- **The chat widget:** open `http://127.0.0.1:8080/bot/widget` in a browser.
- **The bot in a terminal:** `php api/bin/bot.php` (on a Windows console, run `chcp 65001`
  first if you type accented text). It exits 0 when the session completed, 1 when it was
  abandoned or the input ended, 2 for usage errors.
- **The report:**

```
php api/bin/report.php
```

  On the demo data plus your one conversion it shows 14 sessions, 12 conversions (6 via
  `/convert`, 6 via the bot) and 8 `/convert` requests, and the upstream `latest` row shows 1
  call: yours (the demo's lookups are all cache hits). [REPORTS.md](REPORTS.md) explains every
  section. Over HTTP, with your admin token in the header (never in the URL):

```
curl.exe -H "Authorization: Bearer <your ADMIN_TOKEN>" "http://127.0.0.1:8080/reports"
```

That is the whole acceptance check of P1-09: a working `/convert` with demo data, without Docker.

## 9. Behind a proxy: `TRUSTED_PROXY`

The app counts each request under the connection's address (`REMOTE_ADDR`). Behind a reverse
proxy on the same machine, that address is the proxy's loopback address for every client, so
everyone shares one rate limit, and since loopback is never blocked automatically, no abuser is
ever blocked. Set `TRUSTED_PROXY` in `.env` to the exact address the proxy connects from, as
`REMOTE_ADDR` shows it (`127.0.0.1` and `::1` are different strings):

```
TRUSTED_PROXY=127.0.0.1
```

Then, for requests from that peer only, the app uses the **last** address in `X-Forwarded-For`
(the one your proxy appended), so the proxy must append the client's address to that header.
Earlier entries come from the client and are ignored. The trusted proxy itself is never blocked
automatically. Without a proxy, leave `TRUSTED_PROXY` empty.

## 10. Monitoring: `GET /health`

Monitors must use **`GET /health`**:

- 200 `{"ok":true,"data":{"status":"ok"}}` when the database opens, its schema is current and it
  accepts a write;
- 503 `UNHEALTHY` otherwise. `GET /health` is the one request that answers on a database behind
  the code, a corrupt schema version or a database that can't be opened; every other request
  then gets 500 `INTERNAL`.

**Don't use `HEAD`.** On a healthy database `HEAD /health` is a 404, and every 404 counts toward
the IP's automatic block (`block.error_responses`); on a broken one it is a 500. The public view
says nothing else. With `Authorization: Bearer <ADMIN_TOKEN>`, `/health` adds the database, rate
table, breaker and quota details; a wrong token gets 401, which counts toward a block like any
4xx. Point monitors at the public view: the admin view may ask upstream's `/quota` once per
`health.quota_cache_minutes` (60), and whether upstream counts those calls against the monthly
quota is not yet known (ADR 0012 second amendment).

## 11. Configuration

### How config changes in Phase 1

The settings are **read-only defaults in `api/src/Config/defaults.php`**: to change one, edit
that file and restart the server (Phase 3 adds a config API). Each value is type-checked when it
is read, so a wrong type fails loudly. The keys and defaults:

| Key | Default | Meaning |
|---|---|---|
| `installments.default` | `1` | Installments when none are given |
| `installments.max` | `60` | Most installments |
| `amount.max` | `1000000` | Largest total in USD |
| `bot.max_retries` | `3` | Failed answers at one step before the bot gives up |
| `bot.session_ttl_minutes` | `30` | A bot session expires this long after its last message |
| `bot.frontend` | `static` | Not read yet (Phase 3, Typebot) |
| `rates.max_stale_hours` | `48` | How old a stored rate may be to serve it when a refresh fails |
| `ratelimit.per_minute` | `30` | Requests per IP per minute on `/convert` and `/bot/message` together |
| `cors.allowed_origins` | `[]` | Origins that may embed the widget or call `/bot/message` |
| `reports.timezone` | `America/Bogota` | The time zone of report days |
| `health.quota_cache_minutes` | `60` | How long the admin `/health` view caches upstream's `/quota` |
| `llm.enabled`, `llm.confidence_threshold` | `false`, `0.8` | Not read yet (Phase 2) |
| `requests.retention_days` | `30` | Request-log retention for `--prune` |
| `block.window_minutes` | `10` | The window both block thresholds count in (1 to 1440) |
| `block.rate_limit_hits` | `5` | Rate-limited minutes in the window that block an IP |
| `block.error_responses` | `100` | Other 4xx answers in the window that block an IP |
| `block.duration_minutes` | `60` | How long an automatic block lasts (1 to 525600) |

### `ratelimit.per_minute`

One limit covers `/convert` and `/bot/message`; the 31st request from one IP in a one-minute
window gets 429 `RATE_LIMITED`. Changing the value **reinterprets the stored counts at once**:
the counts of the last `block.window_minutes` are compared with the new limit, so lowering it can
turn minutes that were under the old limit into rate-limited minutes, and block an IP that
seemed fine a moment ago. The effect lasts at most one window.

### `cors.allowed_origins`

Each entry must be exactly what a browser sends in its `Origin` header:

- `http://` or `https://`, then a lowercase host name or a bracketed IPv6 address;
- an optional port, but not the scheme's default (no `:443` on `https`, no `:80` on `http`) and
  without leading zeros;
- **no path and no trailing slash**; `*` and `null` are never allowed.

```
'cors.allowed_origins' => ['https://shop.example.com', 'http://localhost:3000'],
```

An invalid entry is dropped, and the error log gets one line with the number dropped
(`fx-installments config: cors.allowed_origins dropped 1 invalid entry (…)`), never the values.
The same checked list feeds CORS on `/bot/message` and the widget page's `frame-ancestors`.

## 12. Embedding the widget

The widget is plain JavaScript; it never computes or formats money. Two ways to put it on another
site, `https://shop.example.com` in these examples, with the API at `https://API_HOST`. Either way
the host page's origin must be in `cors.allowed_origins`.

**An iframe:**

```
<iframe src="https://API_HOST/bot/widget" title="Installments chat"></iframe>
```

The widget page allows framing only by itself and the allowed origins (`frame-ancestors`). If the
host page sends a Content-Security-Policy, it must allow the API origin in `frame-src`.

**One `<script>`:**

```
<script src="https://API_HOST/bot/widget.js" defer></script>
```

It renders after the tag, or inside the element named by `data-fx-target="some-id"`, and loads
`widget.css` and posts to `/bot/message` next to its own URL, with CORS. If the host page sends a
Content-Security-Policy, it must allow the API origin in `script-src`, `style-src` and
`connect-src`. The script then runs under the host page's CSP and styles, so the widget page's
own CSP and Trusted Types don't protect it there.

Either way the session opens only when the user first focuses the message box or presses "Start
chat". A blocked IP's browser gets the 403 without CORS headers, so an embedded widget shows its
generic "can't be reached" text.

## 13. For contributors

```
make init
make check
```

- `make init` runs `composer install` in `api/` and installs the git hooks from the main checkout
  (in a linked worktree it only checks them). The first install over hooks this project didn't
  write needs `make init FORCE=1`.
- `make check` is the whole gate, the one the pre-push hook and CI run: `composer validate
  --strict`, the PHP lint, the Unit and Acceptance suites (with the network flags of ADR 0018)
  and the guard tests (they need `sh`; on Windows, Git Bash).
- `make acceptance TEST=api/tests/Acceptance/P1_09_SeedAndDocsTest.php` runs one test file.
- On Windows, `make` is GNU Make 4.4.1 and the Makefile calls `composer.bat`; override it with
  `make COMPOSER_BIN=...`.
- **Node is optional locally.** The widget harness (`api/tests/js/widget.test.js`) runs from the
  suite when `node` is on `PATH` and is skipped without it; CI sets `CI`, and there a missing
  `node` fails the run instead.
- Never run the tests or the CLIs against `api/var/app.db` by hand to try something; the suite
  makes its own temporary databases.
