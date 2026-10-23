# Local install

This guide takes a clean checkout to a working `/convert`, the chat widget and a report with demo
data on one machine, **without Docker** (ADR 0005: the local install is the only path in Phases 1
and 2; Docker arrives in P3-01).

Commands are run **from the repository root**. Each one works as typed in PowerShell 5.1 or later
(Windows) and in a Linux or macOS shell, unless the text before its block names one shell
(PowerShell, Linux and macOS, or `cmd.exe`). Blocks the text calls `php.ini`, `.env` or
`defaults.php` lines are file contents, and output blocks show what a command prints. The
repository path may contain spaces, so keep the quotes around paths.

## 1. Prerequisites

- **PHP 8.3, release 8.3.36 or later** (not 8.4 or 8.5; ADR 0001 fourth amendment) with the
  extensions `pdo_sqlite`, `curl`, `mbstring` and `openssl` (`api/composer.json` requires all four).
  Enable `zip` too, so Composer can unpack packages. Older 8.3 releases have CVE-2026-109618 and
  CVE-2026-109619, which can allow remote code execution (ADR 0001 third amendment).
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
Debian and Ubuntu). Check that HTTPS works (this checks `openssl.cafile`):

```
php -r "echo file_get_contents('https://example.com') ? 'ok' : 'fail';"
```

The upstream client uses curl, which reads `curl.cainfo`; this shows both settings, which should
name the same file:

```
php -r "echo 'curl.cainfo = ', ini_get('curl.cainfo'), PHP_EOL, 'openssl.cafile = ', ini_get('openssl.cafile'), PHP_EOL;"
```

### `php.ini` settings for the server

Set these in the `php.ini` the server uses:

```
expose_php = Off
display_errors = Off
log_errors = On
file_uploads = Off
session.upload_progress.enabled = 0
post_max_size = 64K
```

- `display_errors = Off`: with it on, a PHP startup warning (an oversized POST, for example) is
  printed as an HTML page with status 200 before the app runs. The app turns display off itself
  once it runs, and section 7 also passes `-d display_errors=0`.
- `expose_php = Off`: PHP adds `X-Powered-By` before the app runs; the app removes it from every
  answer, and this stops PHP sending it at all.
- `session.upload_progress.enabled = 0` closes a confirmed CVE-2026-109619 path (the app uses no
  PHP sessions); set it in `php.ini`, not through `ini_set()` or `.user.ini`, and restart the
  server. `file_uploads = Off` and `post_max_size = 64K` cut the multipart work PHP does before
  the app runs (the app takes no uploads, and its largest request body is 4 KiB,
  `/bot/message`); `post_max_size` bounds only a request that declares its length. None of these
  fixes either CVE; only PHP 8.3.36 or a later 8.3 release does.

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
| `DB_DSN` | The PDO DSN. **Leave it empty for the default**, the SQLite file `api/var/app.db`. A relative SQLite path is read from the repository root. Only `php api/bin/migrate.php` (without `--prune`) creates a missing SQLite file and its folder; every other command and the server refuse it (section 5). |
| `DB_USER`, `DB_PASS` | Database credentials; leave them empty for SQLite. |
| `TRUSTED_PROXY` | The one peer address whose `X-Forwarded-For` is believed. Empty when no proxy is in front (section 9). |
| `DATA_ROOT`, `BACKUP_ROOT` | Placeholders for protected folders; Phase 1 doesn't use them. |

To make an admin token:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

For the command-line tools (`php api/bin/…`, `php api/db/…`), a variable already set in the
environment wins over `.env` (the file never overrides one). PHP's built-in server is different:
it keeps such a variable over `.env`'s value only when `E` is in `variables_order`, which the demo
command of section 7 sets with `-d variables_order=EGPCS`; with the usual `GPCS`, `.env`'s value
wins whenever `.env` sets the key (ADR 0024).

## 4. Install the dependencies

```
composer install --working-dir=api
```

On a machine that won't run the tests you may add `--no-dev`.

## 5. Create the database: `migrate.php`

The app **never changes the schema itself** (ADR 0019). `bin/migrate.php` creates the database
file and its folder if they are missing, and applies every pending migration:

```
php api/bin/migrate.php
```

On a new checkout it prints:

<!-- output: migrate -->
```
migrate: applied 1 p1_01_baseline
migrate: applied 2 phase1_tables
migrate: applied 3 events_amount_usd_cents
migrate: schema version 0 -> 3
```

and on a database that is already current, `migrate: schema is current (version 3)`. It exits 0
when done, 1 on failure (`migrate: failed: <exception class>: <message>` on stderr, `prune:` with
`--prune`; a missing file and, for `--prune`, a schema behind the code print their fixed text
instead) and 2 for usage errors.

**Run it after `composer install` and after every `git pull`**, before starting the server:

- **One migrate at a time.** Two runs at once are not coordinated.
- **Back up `api/var/app.db` first, if it exists** (a first install has nothing to back up).

SQLite keeps recent writes in a separate `app.db-wal` file while the database is in use, so a
plain file copy can miss them or catch them half-written. Make the backup with SQLite itself
instead: `VACUUM INTO` writes one consistent, self-contained file, and is safe while the server
runs. This line works as typed in PowerShell and in a Linux or macOS shell, from the repository
root; it writes `api/var/app.db.bak-<date and time>` (in `date.timezone`, or UTC when that is
not set) and never overwrites an older backup (if that name already exists, it fails instead):

<!-- command: backup -->
```
php -r "if (is_file('api/var/app.db')) { (new PDO('sqlite:api/var/app.db'))->exec('VACUUM INTO \'api/var/app.db.bak-' . date('Ymd-His') . '\''); echo 'backed up', PHP_EOL; } else { echo 'nothing to back up', PHP_EOL; }"
```

`VACUUM INTO` needs SQLite 3.27 or later. This shows the version PHP uses:

```
php -r "echo (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn(), PHP_EOL;"
```

If the backup line reports that the database is locked, run it again. Where the `sqlite3` tool is
installed, its `.backup` command makes the same kind of copy, but under a fixed name it
overwrites each time (add a date to keep several):

```
sqlite3 api/var/app.db ".backup 'api/var/app.db.bak'"
```

If `DB_DSN` points at another file, use that path instead. To **restore** a backup, stop the
server and every CLI, move `api/var/app.db` and any `app.db-wal` and `app.db-shm` next to it out
of the way (a leftover write-ahead log must not meet the restored file), then copy the backup to
`api/var/app.db`. Backups hold the request log's raw IPs, and nothing prunes them: see the privacy
note in [README.md](README.md).

- **A missing database file is refused, never created.** Every command but plain
  `php api/bin/migrate.php` (`--prune` included, as `prune:`) exits 1 with
  `<name>: failed: no database file (run php api/bin/migrate.php first)`, and the server answers
  500 `INTERNAL` (`GET /health` 503); no file or folder is made (ADR 0019 second amendment).
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

1. Stop the server and back the file up as it is (the backup line above works on it).
2. **Restore the backup** taken before the last migrate, if you have one, and run migrate again.
3. Otherwise, check which schema the file really has and set the row to that version. Version 3
   has the seven tables `app_state`, `events`, `ip_blocks`, `rate_cache`, `rate_limits`,
   `requests` and `sessions`, and `events` has an `amount_usd_cents` column (`api/db/schema.sql`
   is the full version 3 schema). Run these on the existing file only (opening a missing one
   creates it empty), and if `DB_DSN` points at another file, use that path instead. First the
   three checks, with the `sqlite3` tool, which is not one of the prerequisites:

   ```
   sqlite3 api/var/app.db ".tables"
   sqlite3 api/var/app.db "PRAGMA table_info(events);"
   sqlite3 api/var/app.db "SELECT key, value FROM app_state;"
   ```

   Without `sqlite3`, the same three through PHP (each line works as typed in PowerShell and in a
   Linux or macOS shell):

   ```
   php -r "echo implode(PHP_EOL, (new PDO('sqlite:api/var/app.db'))->query('SELECT name FROM sqlite_master WHERE type = \'table\' ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)), PHP_EOL;"
   php -r "echo implode(PHP_EOL, (new PDO('sqlite:api/var/app.db'))->query('SELECT name FROM pragma_table_info(\'events\')')->fetchAll(PDO::FETCH_COLUMN)), PHP_EOL;"
   php -r "print_r((new PDO('sqlite:api/var/app.db'))->query('SELECT key, value FROM app_state')->fetchAll(PDO::FETCH_KEY_PAIR));"
   ```

   Only if the checks show version 3, set the row, with `sqlite3` or with PHP:

   ```
   sqlite3 api/var/app.db "UPDATE app_state SET value = '3' WHERE key = 'schema_version';"
   php -r "echo (new PDO('sqlite:api/var/app.db'))->exec('UPDATE app_state SET value = \'3\' WHERE key = \'schema_version\''), ' row(s) updated', PHP_EOL;"
   ```

   If the row is missing (`0 row(s) updated`), insert it instead, with `sqlite3` or with PHP:

   ```
   sqlite3 api/var/app.db "INSERT INTO app_state (key, value, updated_at) VALUES ('schema_version', '3', CAST(strftime('%s', 'now') AS INTEGER));"
   php -r "echo (new PDO('sqlite:api/var/app.db'))->exec('INSERT INTO app_state (key, value, updated_at) VALUES (\'schema_version\', \'3\', CAST(strftime(\'%s\', \'now\') AS INTEGER))'), ' row(s) written', PHP_EOL;"
   ```

   Never set a version higher than the schema shows: migrate would skip the missing parts. A
   version that is too low is safe with the current migrations (1 to 3), because the later
   migrations then fail loudly and roll back.
4. `php api/bin/migrate.php` must then print `migrate: schema is current (version 3)`.

### A daily `--prune` job

```
php api/bin/migrate.php --prune
```

It deletes rate-limit windows older than a day, closed sessions older than 30 days, request-log
rows older than `requests.retention_days` (30) and IP blocks that ended at least
`block.window_minutes` ago, and prints one line per table (here on a new database):

<!-- output: prune -->
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

Windows, in PowerShell (Task Scheduler; use your own paths to `php.exe` and the checkout; `php`
alone is not enough, because a task doesn't get your terminal's `PATH`):

```
$action = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument '"D:\path with spaces\fx-installments\api\bin\migrate.php" --prune'
$trigger = New-ScheduledTaskTrigger -Daily -At 3:30am
Register-ScheduledTask -TaskName "fx-installments prune" -Action $action -Trigger $trigger
```

A task registered like this runs **only while you are logged on**. To run it whether you are
logged on or not, register it with a principal instead of the first `Register-ScheduledTask`
line (add `-Force` to replace a task you already registered). Registering with a principal may
need an elevated PowerShell; the `S4U` logon stores no password and gives the task no network
shares, which the prune doesn't need:

```
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U
Register-ScheduledTask -TaskName "fx-installments prune" -Action $action -Trigger $trigger -Principal $principal
```

(In Task Scheduler's window, the same switch is "Run whether user is logged on or not".) In
`cmd.exe`, `schtasks` does the same; from PowerShell, put `--%` after `schtasks` so PowerShell
passes the rest unchanged, because `\"` is not a PowerShell escape:

```
schtasks --% /Create /SC DAILY /ST 03:30 /TN "fx-installments prune" /TR "\"C:\php\php.exe\" \"D:\path with spaces\fx-installments\api\bin\migrate.php\" --prune"
```

Drop the `--%` when you type it in `cmd.exe`. `schtasks` tasks also run only while you are logged
on, unless you add `/RU <user> /RP` (it then asks for the password).

Linux and macOS (`crontab -e`). Cron runs with a minimal `PATH`, so give php's full path
(`command -v php` shows it), and keep the output, since a failing prune is otherwise silent:

```
30 3 * * * /usr/bin/php "/path/to/fx-installments/api/bin/migrate.php" --prune >> "/path/to/fx-installments/api/var/prune.log" 2>&1
```

## 6. Demo data: `seed_demo.php`

To see the reports filled before any real traffic, seed demo data into a **throwaway database**,
never into the one real traffic will use. Demo rows stay in `/reports` for good (`events` are
never pruned), and the demo's completed sessions on both channels would pass a real end-to-end
check, such as P1-10's "one completed session per channel", on their own.

The simplest way is a separate `DB_DSN` set in the shell, while `.env` keeps pointing at the real
database. For the command-line tools a variable set in the environment wins over `.env`, so every
`php api/…` command in that shell uses the demo file; the server does too, but only when started
with the demo command of section 7 (`-d variables_order=EGPCS`). A relative SQLite path is read
from the repository root.

PowerShell:

```
$env:DB_DSN = "sqlite:api/var/demo.db"
```

Linux and macOS:

```
export DB_DSN=sqlite:api/var/demo.db
```

Then, in that same shell, migrate the demo file and seed it:

```
php api/bin/migrate.php
php api/db/seed_demo.php
```

It prints a line like this one (the dates are the default report period, which moves with the
day you run it):

<!-- output: seed_demo -->
```
seed_demo: wrote 14 sessions, 92 events and 97 request-log rows, 2026-10-16 to 2026-10-22 (America/Bogota)
```

It writes, in one transaction:

- 14 bot sessions on the `static` and `cli` channels, spread over the default report period (the
  last 7 days in `reports.timezone`): completed, abandoned at several steps, never answered, and
  two still open, which expire within 30 minutes of seeding (`bot.session_ttl_minutes`);
- seven `/convert` requests, a few error responses and one IP that was rate-limited, blocked and
  unblocked;
- the matching request-log rows, from documentation addresses only (192.0.2.0/24,
  198.51.100.0/24, 203.0.113.0/24).

Amounts are integer cents. It writes **no rates** (nothing in `rate_cache`, the breaker or the
quota cache), so your first real conversion still makes one real `/latest/USD` call.

**Telling demo rows apart.** Every event the seed writes has `"demo": true` in its meta. The two
open sessions are closed later by a sweep (the next bot message or report after they expire),
and those `bot.session_abandoned` events, written by the app, don't carry the marker; find them
by their fixed session ids, `96e07728de607b51e754a996f4a232fc` (static) and
`95d7296436cf2be91541220a218d1d65` (cli). `sessions` rows have no marker either (every demo
session id is fixed), and the demo's `requests` rows all come from the documentation addresses
above.

It refuses (exit 1) a database that already has events or sessions, so it never mixes demo rows
into real ones and never runs twice. Exit codes: 0 done, 1 refused or failed, 2 usage.

**Before real use, and before an end-to-end check such as P1-10, start from a database without
demo rows.** With the throwaway file above, leave the demo shell (or clear the variable:
`Remove-Item Env:DB_DSN` in PowerShell, `unset DB_DSN` in a Linux or macOS shell); the commands
then use `.env`'s database again, and you can delete `api/var/demo.db` and its `-wal` and `-shm`
files once the server is stopped. If you seeded the real `api/var/app.db` anyway, there is no
undo: stop the server, move `api/var/app.db` and any `app.db-wal` and `app.db-shm` away, and run
`php api/bin/migrate.php` again for a new, empty database.

## 7. Start the server

```
php -d display_errors=0 -S 127.0.0.1:8080 -t api/public api/public/index.php
```

`api/public/index.php` is the single entry point; every request goes through it. Keep the
terminal open: PHP's development server writes the app's error log lines there. That command is
for real use: start it in a shell without `DB_DSN` set, and it opens the database `.env` names
(`api/var/app.db` when `DB_DSN` is empty).

**To tour the demo data**, start the server in the shell where `DB_DSN` points at the demo file
(section 6), with one more option:

```
php -d variables_order=EGPCS -d display_errors=0 -S 127.0.0.1:8080 -t api/public api/public/index.php
```

The built-in server doesn't pass the shell environment to phpdotenv unless `E` is in
`variables_order` (the usual `php.ini` value is `GPCS`), so without that option `.env`'s
`DB_DSN` wins and the server opens the real database instead of the demo file.

**Bind it to `127.0.0.1`**, as above, also when a reverse proxy on the same machine forwards to
it (section 9): the proxy reaches it there, and nothing else can. Bind another address only when
the proxy runs on another machine, and then let only that proxy reach the port (a firewall rule).
**Never expose `php -S` directly to the internet**: it is PHP's development server, with no TLS,
and Phase 3 puts nginx and php-fpm in front instead (P3-01).

## 8. Try it

In a second terminal. For the demo tour, point it at the demo file too, so the bot and the report
below use the same database as the server. PowerShell:

```
$env:DB_DSN = "sqlite:api/var/demo.db"
```

Linux and macOS:

```
export DB_DSN=sqlite:api/var/demo.db
```

The HTTP examples come in two forms: in Windows PowerShell 5.1, `curl` is an alias for
`Invoke-WebRequest`, so type `curl.exe` there.

The health check, PowerShell:

```
curl.exe "http://127.0.0.1:8080/health"
```

Linux and macOS:

```
curl "http://127.0.0.1:8080/health"
```

It answers `{"ok":true,"data":{"status":"ok"}}`.

A conversion, with your real key (one `/latest/USD` call refreshes every rate; later conversions
in the same refresh window make none), PowerShell:

```
curl.exe "http://127.0.0.1:8080/convert?amount=200&cy=BRL&installments=3"
```

Linux and macOS:

```
curl "http://127.0.0.1:8080/convert?amount=200&cy=BRL&installments=3"
```

The numbers depend on today's rate. At a rate of 5.2291 (the test suite's mocked upstream) the
answer is:

<!-- output: convert -->
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

Without options it covers the last 7 days, today included. To cover exactly the demo's days, pass
the two dates `seed_demo.php` printed, with `--to` moved to today if a day has passed since you
seeded, so your conversion is inside too:

```
php api/bin/report.php --from=2026-10-16 --to=2026-10-22
```

On the demo data plus your one conversion, over that period (or with no options, on the day you
seed and convert), it shows 14 sessions, 12 conversions (6 via `/convert`, 6 via the bot), 8
`/convert` requests and 1 upstream call, yours: the demo's lookups are all cache hits, so the
`latest` row's 1 call is your real one. These lines of the table:

<!-- output: report after the conversion -->
```
  Sessions started                         14
  Conversions                              12
    via /convert                            6
    via the bot                             6
  USD converted                      6,289.88
  /convert requests                         8
  Upstream calls                            1
  latest         12   11     1      0        0      1       0       0
```

[REPORTS.md](REPORTS.md) explains every section. Over HTTP, with your admin token in the header
(never in the URL), PowerShell:

```
curl.exe -H "Authorization: Bearer <your ADMIN_TOKEN>" "http://127.0.0.1:8080/reports"
```

Linux and macOS:

```
curl -H "Authorization: Bearer <your ADMIN_TOKEN>" "http://127.0.0.1:8080/reports"
```

That is the whole acceptance check of P1-09: a working `/convert` with demo data, without Docker.
Then go back to a database without demo rows before real use (section 6).

## 9. Behind a proxy: `TRUSTED_PROXY`

The app counts each request under the connection's address (`REMOTE_ADDR`). Behind a reverse
proxy on the same machine, that address is the proxy's loopback address for every client, so
everyone shares one rate limit, and since loopback is never blocked automatically, no abuser is
ever blocked. Set `TRUSTED_PROXY` in `.env` to the exact address the proxy connects from, as
`REMOTE_ADDR` shows it (`127.0.0.1` and `::1` are different strings):

```
TRUSTED_PROXY=127.0.0.1
```

With the proxy on another machine, set it to that machine's address, again as `REMOTE_ADDR`
shows it. Then, for requests from that peer only, the app uses the **last** address in
`X-Forwarded-For`
(the one your proxy appended), so the proxy must append the client's address to that header.
Earlier entries come from the client and are ignored. The trusted proxy itself is never blocked
automatically. Without a proxy, leave `TRUSTED_PROXY` empty.

## 10. Monitoring: `GET /health`

Monitors must use **`GET /health`**:

- 200 `{"ok":true,"data":{"status":"ok"}}` when the database opens, its schema is current (or
  ahead of the code) and it accepts a write;
- 503 `UNHEALTHY` otherwise. `GET /health` is the one request that answers on a missing database
  file, a database behind the code, a corrupt schema version or a database that can't be opened;
  every other request then gets 500 `INTERNAL`.

**Don't use `HEAD`.** On a healthy database `HEAD /health` is a 404, and every 404 counts toward
the IP's automatic block (`block.error_responses`); on a broken one it is a 500. The public view
says nothing else. With `Authorization: Bearer <ADMIN_TOKEN>`, `/health` adds the database, rate
table, breaker and quota details; a wrong token gets 401, which counts toward a block like any
4xx. Point monitors at the public view: the admin view may ask upstream's `/quota` once per
`health.quota_cache_minutes` (60), and upstream counts those calls against the monthly quota:
polling it hourly or more often would use about 744 of the 1,500 calls a month (ADR 0012 third
amendment).

## 11. Configuration

### How config changes in Phase 1

The settings are **read-only defaults in `api/src/Config/defaults.php`**: to change one, edit
that file and restart the server (Phase 3 adds a config API).

That file is tracked by git, so a local edit meets the `git pull` routine of section 5. Keep the
edit out of the way of each pull, for example with a stash:

```
git stash push -m "local config" -- api/src/Config/defaults.php
git pull
git stash pop
```

(or keep it as a local commit and let `git pull` merge it; either way, resolve a conflict in that
file by hand). After every edit, and after every pull, check `GET /health` and one `/convert`
(section 8).

Each value is type-checked when it is read, and some are range-checked, so a mistake fails
loudly instead of being guessed:

- The app reads `ratelimit.per_minute`, `cors.allowed_origins`, `reports.timezone`,
  `rates.max_stale_hours`, `health.quota_cache_minutes` and the four `block.*` keys while it
  wires up each request, before routing. A wrong type there, a `ratelimit.per_minute` below 1, or
  a `block.*` value out of its range (below) makes **every request answer 500 `INTERNAL`**,
  `GET /health` included, and the error log shows
  `fx-installments INTERNAL before routing: InvalidArgumentException at …`.
- `amount.max` must be an integer of at least 1. One below 1 fails when the config loads, so
  every request (`GET /health` included) answers 500, and `bin/bot.php`, `bin/report.php`,
  `migrate.php --prune` and `seed_demo.php` fail; `bin/ip.php`, `bin/upstream.php` and plain
  migrate don't read the config.
- Every other key is read only by the requests that use it: a wrong type makes those answer 500
  (for example `amount.max` breaks `/convert`, the bot and the reports, and
  `bot.session_ttl_minutes` breaks the bot and the reports, whose sweep reads it; both also stop
  `seed_demo.php`).
- `requests.retention_days` of the wrong type, or below 1, and a `block.window_minutes` out of
  range, make `php api/bin/migrate.php --prune` exit 1
  (`prune: failed: InvalidArgumentException: …`). A wrong type of `requests.retention_days`
  also breaks `/reports` and `bin/report.php`.
- A `reports.timezone` that is a string but not a known zone breaks only the reports (and
  `seed_demo.php`).

The keys and defaults:

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

In `api/src/Config/defaults.php`:

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
- Never point the test suite at `api/var/app.db`; it makes its own temporary databases. Try
  things out in a throwaway database (section 6).
