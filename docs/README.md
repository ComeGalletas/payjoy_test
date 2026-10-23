# FX Installments

FX Installments turns a **total** price in USD into a target currency and splits it into monthly
installments. It is a PHP 8.3 API (no framework, SQLite through PDO) plus a deterministic chatbot,
and it gets its rates from ExchangeRate-API with one `/latest/USD` call per refresh.

- **Customers** use the bot: the built-in chat widget, the terminal bot, or any front-end that
  speaks `POST /bot/message` (Typebot comes in Phase 3).
- **Operators** use the reports (`GET /reports`, `php api/bin/report.php`), `GET /health` and a
  few command-line tools.

Phase 1 (the deterministic MVP) runs locally without Docker. The quick start below gets you
chatting with the bot in a few minutes. [INSTALL_LOCAL.md](INSTALL_LOCAL.md) is the full guide:
backups, the daily prune job, demo data, proxies, monitoring and every setting.

## Quick start

**You need:**
- PHP 8.3, release 8.3.36 or later (not 8.4), with the extensions `pdo_sqlite`, `curl`,
  `mbstring`, `openssl` and `zip` (Composer needs `zip`); `php -m` lists them;
- Composer 2 and git;
- an ExchangeRate-API key (next section).

**On Windows, PHP needs two edits in its `php.ini`.** `php --ini` shows which file is loaded. If
none is, copy `php.ini-development` next to `php.exe` as `php.ini`.
- Set `extension_dir` to the full path of the `ext` folder next to `php.exe` (for example
  `extension_dir = "C:\php\ext"`), and uncomment `extension=curl`, `extension=mbstring`,
  `extension=openssl`, `extension=pdo_sqlite` and `extension=zip`. Then check `php -m`.
- Point both CA settings at a CA bundle, or every rate lookup fails. Git for Windows ships one:

  ```
  curl.cainfo = "C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt"
  openssl.cafile = "C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt"
  ```

Linux and macOS normally need neither. [INSTALL_LOCAL.md](INSTALL_LOCAL.md) section 1 has the
details and the recommended server settings.

Run every command from the repository root. They work as typed in PowerShell and in a Linux or
macOS shell, except where the text names one shell.

1. Get the code and its dependencies:

   ```
   git clone https://github.com/ComeGalletas/payjoy_test.git "fx-installments"
   cd "fx-installments"
   composer install --working-dir=api
   ```

2. Create `.env` from the template: `Copy-Item .env.example .env` (PowerShell) or
   `cp .env.example .env` (Linux and macOS). Open it and set `EXCHANGERATE_API_KEY` to your key.
   Leave the other keys empty for now.
3. Create the database (`api/var/app.db`):

   ```
   php api/bin/migrate.php
   ```

4. Start the server, and keep this terminal open (the app's error log appears there):

   ```
   php -d display_errors=0 -S 127.0.0.1:8080 -t api/public api/public/index.php
   ```

5. Open the chat widget at <http://127.0.0.1:8080/bot/widget> and press **Start chat**. Or chat
   in a second terminal with `php api/bin/bot.php` (on a Windows console, run `chcp 65001` first
   if you type accented text).

**If the bot's last answer is an error:**
- "The exchange rate service is not responding" means PHP can't reach upstream over HTTPS. Fix
  the CA bundle, restart the server, then run `php api/bin/upstream.php reset` (or wait for the
  backoff: 1 minute after the first failure, up to an hour after repeated ones).
- "Exchange rates are temporarily unavailable" usually means a problem with the key (see the next
  section). A reached quota or a malformed upstream answer gives the same text;
  `php api/bin/upstream.php status` shows which.

### Test the bot by hand

The bot greets you, then asks for three things in order. Try this conversation:

| You type | The bot answers |
|---|---|
| `200` | asks which currency or country |
| `Brazil` (or `BRL`, or a button) | asks how many monthly installments (default 1) |
| `3` | 200 USD in BRL at today's rate, split into 3 monthly payments; when the total doesn't split evenly, the last payment absorbs the remainder |

Things worth trying:
- **Amounts:** `200.50`, `1,000`, `1.000,50` or `$200` are read. `abc` and an ambiguous `1.500`
  (a decimal, or thousands?) both get "I couldn't read that amount…", and `0` gets "The total
  must be more than 0…". Each counts as one failed answer.
- **Currencies:** a code (`COP`), a country (`Mexico`) or a name (`euro`). A name several
  currencies share (`peso`, `dollar`) asks you to type the code.
- **Installments:** press **Just one payment**, or type a whole number up to the maximum (60).
  Too many installments for a tiny total gets its own message (try `0.10`, `JPY`, `60`).
- **Retries:** three failed answers at one step end the session. The widget then offers **Start
  a new conversation**; the terminal bot exits.

The API directly, without the bot. In PowerShell type `curl.exe`, since `curl` is an alias there;
in Linux and macOS, type `curl`:

```
curl.exe "http://127.0.0.1:8080/health"
curl.exe "http://127.0.0.1:8080/convert?amount=200&cy=BRL&installments=3"
```

`/health` answers `{"ok":true,"data":{"status":"ok"}}`. `/convert` answers the raw numbers plus a
`display` block, the strings every front-end shows. `php api/bin/report.php` then shows your test
sessions and conversions, with no admin token.

To run the tests: `composer check --working-dir=api` (lint plus the unit and acceptance suites;
`make check` adds the guard tests). None of them calls ExchangeRate-API.

## The ExchangeRate-API key

The app gets its rates from [ExchangeRate-API](https://www.exchangerate-api.com).

1. Sign up for the free plan at <https://www.exchangerate-api.com>. It allows 1,500 requests a
   month, and its rates update once a day.
2. Copy your API key from the dashboard, and put it in `.env`, at the repository root:

   ```
   EXCHANGERATE_API_KEY=your-key-here
   ```

   The key is letters, digits, `-` and `_`, with no quotes or spaces. `.env` is git-ignored:
   never commit it, and never put the key in a URL you share. It stays on the server, and the
   app never shows it.
3. No restart is needed: the server and the CLIs read `.env` on every request or run. After an
   invalid key, also run `php api/bin/upstream.php reset` (below).

**How the app spends the quota.** One `/latest/USD` call refreshes every rate, and the app calls
again only when upstream says the next update is due. So refreshes cost about 30 calls a month,
whatever the traffic; the admin view of `/health` adds `/quota` calls on top. Every conversion
is computed locally from the stored table. If a refresh fails, the app serves the last table
while its rate is less than `rates.max_stale_hours` (48) old, counted from `rate_as_of`, with
`stale: true`, and the bot adds a note.

**When the key is wrong or missing:**
- **Empty or malformed key:** every rate lookup fails with `UPSTREAM_AUTH` without calling
  upstream, and the breaker stays closed. The bot still runs up to the last step, then says
  "Exchange rates are temporarily unavailable. Please try again later."
- **Invalid or inactive key:** the breaker stops all calls until you fix the key and run
  `php api/bin/upstream.php reset`.
- **Check the state:** `php api/bin/upstream.php status` shows the breaker, the stored rate
  table's times, and whether a key is set (not whether it is valid; never the key itself).
- **Quota reached:** calls stop until the 1st of the next month (UTC), and cached rates are served
  while they are young enough (ADR 0012). Upstream's own quota may reset on another day; the admin
  view of `/health` shows both dates.

Tests never use your key: they run with a fake key against a mocked upstream, and the network is
switched off (ADR 0018).

## The parts

| Part | What it does | Where |
|---|---|---|
| `GET /convert?amount&cy&installments` | Converts a total USD amount and splits it; answers raw numbers and a `display` block | `api/src/Http/ConvertEndpoint.php`, `api/src/Convert/` |
| `POST /bot/message` | The static flow engine: one contract for every chat front-end | `api/src/Flow/`, `api/flows/default.yaml` |
| `GET /bot/widget` | The built-in chat widget, embeddable by iframe or one `<script>` | `api/resources/widget/` |
| `GET /reports` (admin) | Funnel, conversions, errors, upstream calls and the request log, as JSON or CSV | `api/src/Metrics/`; see [REPORTS.md](REPORTS.md) |
| `GET /health` | Public status for monitors; details with the admin token | `api/src/Http/HealthEndpoint.php` |
| `php api/bin/bot.php` | The bot in a terminal (channel `cli`) | |
| `php api/bin/report.php` | The report as a table, CSV or JSON | |
| `php api/bin/migrate.php` | Creates and upgrades the database; `--prune` deletes expired rows | |
| `php api/bin/ip.php` | Manual IP blocks: `block`, `unblock`, `list` | |
| `php api/bin/upstream.php` | The upstream breaker: `status`, `reset` | |
| `php api/db/seed_demo.php` | Demo data for the reports, on a new, throwaway database (INSTALL_LOCAL.md section 6) | |

Every JSON answer is an envelope: `{"ok": true, "data": {...}}` or
`{"ok": false, "error": {"code": "...", "message_for_user": "..."}}`. Front-ends branch on `ok` and
show `message_for_user` as it is. The widget's files, the 204 `/favicon.ico` and the CSV report
are the only answers without the envelope.

## The money rule

All money math happens in the API, at full precision, and **only
`InstallmentCalculator::display()` rounds**. It builds the `display` block exactly from the
amount's cents and the stored rate (`rate_scaled`), in the currency's minor units:

- `display.amount_usd`, `display.rate`, `display.converted`, `display.installment`,
  `display.last_installment`, `display.regular_count`, `display.even`, `display.rate_as_of`.
- The installments always add up to `display.converted` exactly; the last one absorbs the
  remainder.

Every front-end (the widget, the terminal bot, a future Typebot flow, your own page) shows these
**display strings verbatim**. Never round, format or recompute the raw numbers (`rate`,
`converted`, `installment_amount`): they are floats for machines, and their last digits can
differ from the display. The bot itself never computes a rate or splits money; its result texts
take only `display.*`, the currency code and the installment count.

Stored money is integer cents (`amount_usd_cents`) and stored rates are integers scaled by 10^10
(`rate_scaled`); no database column holds a float.

## Privacy: the request log and IP blocks

The app records every request it receives, to fight abuse (ADR 0016). Raw IP addresses are
personal data (Colombia's Ley 1581), so this is what is kept, and for how long:

- **The request log.** Every request that reaches the app writes one row in the `requests` table,
  blocked ones included: the raw client IP (the connection's address, or the trusted proxy's
  `X-Forwarded-For` hop when `TRUSTED_PROXY` is set), the method, the path without its query
  string, the status, the latency and whether it was blocked. (A request refused before that,
  while the database can't be used, leaves no row.) Rows are kept `requests.retention_days`
  (30 days) and then deleted by `php api/bin/migrate.php --prune`. **Schedule that job daily**:
  without it nothing is deleted.
- **Rate-limit counters** (`rate_limits`) hold the IP and a per-minute count; `--prune` deletes
  them after a day.
- **Automatic blocks.** An IP over a `block.*` threshold (too many rate-limited minutes, or too
  many other 4xx answers, within `block.window_minutes`) is blocked for
  `block.duration_minutes` (60). Its row in `ip_blocks` holds the raw IP. See every active block,
  with its IP, with `php api/bin/ip.php list`.
- **Manual blocks** (`php api/bin/ip.php block <ip>`) without `--minutes` never expire: they keep
  that IP until an operator removes them with `php api/bin/ip.php unblock <ip>`. `ip.php list`
  counts them separately so they can be reviewed.
- **Ended blocks** (expired or unblocked) are kept one more `block.window_minutes` (10 minutes)
  after their end, so the requests before the block never count again, and then `--prune`
  deletes them.
- **Backups hold raw IPs too.** A database backup (INSTALL_LOCAL.md, section 5) is a copy of the
  request log and the blocks as they were, and nothing prunes it. Delete old backups, or keep
  them under the folder you set as `BACKUP_ROOT` with a retention of their own (the app itself
  doesn't use `BACKUP_ROOT` yet).
- **Block events never hold an IP.** `ip.block` and `ip.unblock` events record the source, the
  reason and the duration, never the address and never the operator's reason text. Events are
  never pruned.
- **Reports never show an IP**, nor a path: `/reports` and `bin/report.php` count requests by
  kind, status class and blocked only.

Outside these three tables the app stores no client IP: not in events, sessions, the error log
or the CLI's stderr. Only `ip.php`'s own output shows IPs, because it is the operator's tool. The
bot never stores what a user typed, only the validated values (amount in cents, currency, a
country when one was typed, installments). One thing is outside the app: PHP's built-in server
(`php -S`) prints each request's client address in its own terminal; if you redirect that output
to a file, that file holds IPs too, and no prune touches it.

## Technical choices

Each choice has an ADR in [adr/](adr/README.md); this is the short version.

- **PHP 8.3, no framework** (ADR 0001). The app is small: a handful of JSON endpoints, a
  state-machine bot and reports. Plain PHP keeps it easy for one person to read end to end. It
  runs with `php -S` today, and in a container from Phase 3 (ADR 0005). There is one entry point
  (`api/public/index.php`) and a small router. Three Composer packages do the work a framework
  would: Guzzle for the upstream HTTP, `symfony/yaml` for the bot's flow file and
  `vlucas/phpdotenv` for `.env`. PHPUnit is for tests only.
- **SQLite through PDO** (ADR 0010). Zero setup for a local install. The SQL stays portable, so
  Postgres can come later. Schema changes run only through `migrate.php`, never inside a request
  (ADR 0019).
- **A deterministic bot, not an LLM** (ADR 0003). The conversation is a fixed pipeline: amount,
  then currency, then installments. Deterministic parsers and a YAML flow make it predictable,
  cheap, fast and testable, with no prompt injection. Phase 2 may add an LLM normalizer for
  free-form input, behind a flag (`llm.enabled`) and in front of the same parsers. Typebot is an
  optional front-end over the same `POST /bot/message` contract (ADR 0004).
- **Exact money** (ADR 0011, ADR 0017). Amounts are stored as integer cents and rates as integers
  scaled by 10^10. Only `InstallmentCalculator::display()` rounds, in each currency's minor units,
  so the installments always add up to the converted total.
- **Few upstream calls** (ADR 0012). One `/latest/USD` call refreshes every rate, the math is
  local, and a breaker protects the quota.

**Structure.**

| Folder | Holds |
|---|---|
| `api/src/` | The code, one folder per area: `Convert` (the money math), `Rates` (upstream client, cache, breaker), `Flow` (the bot engine), `Validation` (the input parsers), `Http` (router, endpoints, rate limit, IP blocks), `Metrics` (events and reports), `Db` and `Config`. `App.php` wires them together. |
| `api/public/` | The single HTTP entry point |
| `api/bin/` | The command-line tools (bot, migrate, report, ip, upstream) and the dev tools (lint, install-hooks) |
| `api/flows/` | The bot's texts and steps (`default.yaml`); change the wording here, no PHP needed |
| `api/resources/widget/` | The chat widget's HTML, CSS and JS |
| `api/db/` | The generated schema and the demo seed |
| `api/var/` | The SQLite database (`app.db`), git-ignored |
| `api/tests/` | Unit and acceptance tests (PHPUnit), guard tests, and the widget's JS tests |
| `docs/` | This guide, the plan, the ADRs and the journal |

## Measuring success in production

Success means customers get a correct installment amount quickly, and the service stays up within
its quota. Each item says where its number comes from: the report (`GET /reports` or
`php api/bin/report.php`, see [REPORTS.md](REPORTS.md)), the stored events, or a tool outside the
app.

**Do customers get an answer?** Mostly the report's funnel, per channel:
- **Completion rate:** completed sessions divided by started ones. This is the headline number.
- **Drop-off per step:** where sessions are abandoned (`amount`, `currency`, `installments`, or
  `result` when the conversion failed upstream) and why (`BOT_MAX_RETRIES`,
  `BOT_SESSION_EXPIRED`, an upstream code).
- **Retries per step:** high retries at `amount` or `currency` mean the parsers miss what people
  type. That is the evidence for Phase 2's LLM normalizer.
- **Message quality:** a step with high `retries` and high `abandoned` in the funnel needs a
  clearer question or retry text (`api/flows/default.yaml`).
- **Never-answered sessions:** people who open the chat and leave. This tells you whether the
  greeting works.
- **Currency mix:** the report's `currencies` and `countries` sections show what people ask for.
  High retries at `currency` mean the synonym table misses names people use; the bot never
  stores what was typed, so find them by trying likely names by hand.

**Are the answers right?** This is a hard requirement, not a trend.
- **Exact totals:** installments always add up to the converted total. The test suite enforces
  this; in production, recompute a sample of conversions from the cents and `rate_scaled` their
  events store.
- **Rate accuracy:** compare a daily sample of stored rates with a second source. The free plan
  updates once a day, so fast-moving currencies can drift from what the checkout charges.
- **Fresh rates:** the share of conversions served with a stale rate, and the age of `rate_as_of`.
  Every conversion event stores both, but the report doesn't summarize them yet; its upstream
  `stale` and `blocked` lookups are a rough proxy.
- **No errors on the money path:** zero `INTERNAL` errors. The report's errors section counts
  every error code, though not by endpoint.

**Is it reliable and affordable?**
- **Availability:** an external monitor on the public `GET /health` (200 versus 503), with alerts.
  Leave out the admin token: the admin view calls upstream's `/quota`, which costs quota.
- **Upstream health and cost:** calls per month against the 1,500 quota (about 30 for refreshes,
  plus any admin `/health` calls), failed calls, lookups the breaker blocked, and the cache hit
  rate (the report's upstream section). Breaker trips aren't counted yet;
  `php api/bin/upstream.php status` shows the current state.
- **Abuse:** rate-limited requests and automatic IP blocks (the report's `errors` and `ip_blocks`
  sections). A sudden rise means an attack, or a limit that is too tight for real users.
- **Speed:** p95 latency of `/convert` and `/bot/message`. The request log stores each request's
  latency, but the report doesn't summarize it yet; add that, or read it from the proxy's logs
  (Phase 3).

**Business impact** (outside the app). The real goal is that customers who see the installment in
their own currency go on to buy. Measure it by joining the bot's completed sessions with the
checkout's conversions, or by A/B testing the widget on a product page. The app has no link to
the checkout yet.

Set a baseline over the first weeks, then set targets. For example: a completion rate above 70%,
stale answers under 1% of conversions, `/health` up 99.9%, p95 latency under 500 ms, and fewer
than 50 upstream calls a month.

## Read on

| Document | For |
|---|---|
| [INSTALL_LOCAL.md](INSTALL_LOCAL.md) | Installing, running and operating the app on one machine |
| [REPORTS.md](REPORTS.md) | Reading `/reports` and `bin/report.php` |
| [PLAN.md](PLAN.md) | The build plan: rules, data model, endpoints, phases and tasks |
| [TODO.md](TODO.md) | The current phase and the next task |
| [../CONTEXT.md](../CONTEXT.md) | The glossary: the exact terms used in code and docs |
| [adr/](adr/README.md) | The architecture decisions and their amendments |
| [GUARDRAILS.md](GUARDRAILS.md) | The hard rules and product invariants, and what enforces them |
| [LOG.md](LOG.md) | The dated journal |
| [../CLAUDE.md](../CLAUDE.md) | How changes are made in this repository |
