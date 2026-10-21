# FX Installments — Final Build Plan

> Agent brief. Read fully before writing code. This file replaces `fx-installments-plan.md` (kept
> as the original draft) and folds in the owner's decisions and the senior-lead review from the
> pre-work session of 2026-10-20. Decisions here are final unless the owner changes them.
>
> **Precedence:** `CLAUDE.md` holds the development rules and beats this plan. A spec beats the
> plan; an accepted ADR in `docs/adr/` beats both until superseded. Raise conflicts instead of
> choosing silently. When a decision is unclear, ask the owner directly.
>
> **Where things live:** this file says *what* to build and how each task is accepted (§10).
> Decisions and their reasons are ADRs (`docs/adr/README.md`). Progress is the checklist in
> `docs/TODO.md`. Terms are in `CONTEXT.md`. Don't copy content between them; link instead.

## 1. Goal

Small, measurable app with two components:

1. **REST API** — `GET /convert?amount=<usd>&cy=<ISO4217>&installments=<n?>` converts a **total**
   USD price into the target currency and splits it into monthly installments, using
   [ExchangeRate-API](https://www.exchangerate-api.com) free tier (1,500 req/month, rates refresh
   once per day, API key sits in the URL path → server-side only).
2. **Chatbot** following a strict linear pipeline: greet → ask the total USD price → ask target
   currency → (optional) installments → call the API → show a friendly message in English. The bot
   never computes rates or splits money itself.

The question the bot answers: *"I bought my phone for $200 USD. How much is my monthly installment
in my local currency?"*

Example (rate 5.2291, 3 installments):

```
GET /convert?amount=200&cy=BRL&installments=3
{
  "ok": true,
  "data": {
    "amount_usd": 200,
    "currency": "BRL",
    "rate": 5.2291,
    "converted": 1045.82,
    "installments": 3,
    "installment_amount": 348.6066666666667,
    "rate_as_of": "2026-10-20T00:00:01Z",
    "stale": false,
    "display": {
      "amount_usd": "200.00",
      "rate": "5.2291",
      "converted": "1,045.82",
      "installment": "348.60",
      "last_installment": "348.62",
      "regular_count": 2,
      "even": false,
      "rate_as_of": "2026-10-20 00:00 UTC"
    }
  }
}
```

The raw numbers are full-precision floats; the exact digits JSON emits may differ in the last
places (`1045.8200000000002`). Tests assert on `display` strings or compare raw numbers with a
tolerance, never on exact float JSON.

Everything must be **measurable**: ExchangeRate calls, cache hits and stale serves, currencies and
countries requested, bot sessions (started / completed / abandoned, with the step), every error by
code, with easy reports.

## 2. Decisions (final)

Reasons and consequences are in the ADR named in each row.

| Topic | Decision | ADR |
|---|---|---|
| Language | PHP 8.3, no framework. Composer: `guzzlehttp/guzzle`, `vlucas/phpdotenv`, `symfony/yaml`; `phpunit/phpunit` as require-dev. | 0001 |
| Database | SQLite via PDO behind a `Database` factory, portable SQL, UTC epoch integers, JSON as TEXT. Postgres support (`Dialect`, second schema) deferred to L-03. | 0010 |
| Chatbot MVP | Static flow in YAML, run by a PHP state machine. **No LLM in the MVP.** Deterministic parsing only. | 0003 |
| LLM | Phase 2, feature-flagged normalizer in front of the deterministic validators. Never replaces validation. | 0003 |
| Typebot | Phase 3, optional front-end calling the same endpoints. Static flow stays as fallback. | 0004 |
| Deployment | Local install without Docker is primary through Phase 2 (PHP 8.3, Composer, `php -S`). Docker compose arrives in Phase 3. | 0005 |
| Amount semantics | `amount` is the **total** USD price. `installment_amount = converted / installments`. | 0006 |
| Money | Full precision in the API. Splitting and display work in the currency's **ISO 4217 minor units**; round down, the **last installment absorbs the remainder**; reject splits under one minor unit. Display strings are English-formatted. See §3a. | 0011 |
| Storage of money | No floats in the database: USD amounts as integer cents, rates as integers scaled by 10^10. | 0017 |
| Request log | Every request is recorded with its raw client IP (kept 30 days); abusive IPs are blocked automatically. | 0016 |
| Test isolation | Tests can't reach the network: `-d allow_url_fopen=0` in the gate scripts plus a stream-wrapper guard; curl/Guzzle covered in P1-04. | 0018 |
| Upstream | One `/latest/USD` call per upstream refresh; always compute `amount × rate` locally; stale fallback; stop calling after quota or key errors. See §3b. | 0012 |
| Amount input | `/convert` is strict (`.` decimal only). The bot also accepts `,` as a decimal or thousands separator; unclear input gets a clarifying question. See §3c. | 0013 |
| Phase 1 scope | Rollups, dashboard, config API, `POST /events`, capability catalog and APCu move to Phase 3. | 0014 |
| Development rules | `CLAUDE.md` governs commits, branches, roles, ADRs and guardrails. | 0008 |
| Doc layout | `CLAUDE.md` layout plus `docs/LOG.md`. | 0009 |

## 3. Non-negotiable rules

- **Sanitization at every boundary.**
  - `amount` on `/convert`: the raw string must match `^\d{1,7}(\.\d{1,2})?$`, then cast; > 0 and
    ≤ `amount.max`. This rejects `1e3`, NaN, INF, signs, spaces and commas.
  - `amount` in the bot: `AmountParser` (§3c) turns user text into that canonical string first,
    then the same strict validator runs.
  - `cy`: trim, uppercase, `^[A-Z]{3}$`, then the allow-list (§3b).
  - `installments`: integer, 1..`installments.max`, and at least one minor unit per installment.
  - User input is never interpolated into an upstream URL.
- **Response envelope** on every JSON endpoint: `{ "ok": true, "data": {...} }` or
  `{ "ok": false, "error": { "code": "...", "message_for_user": "..." } }`. Front-ends branch on
  `ok` and display `message_for_user` verbatim. HTML pages (widget) and CSV reports are the only
  non-envelope responses.
- **Every** meaningful action writes an event through the single `Metrics` class. No ad-hoc
  logging. A failed metrics write is caught and never fails the user's request.
- **Secrets.** Only in `.env` (the owner fills it; agents never read it). The API key never appears
  in responses, events, logs or exception text (§3b). The test suite never loads the real key and
  never reaches the network.
- **Admin token.** `ADMIN_TOKEN` from `.env`, sent only in the `Authorization: Bearer` header,
  compared with `hash_equals`. If the token is unset or empty, every admin request is refused.
  Never in a URL.
- **Output safety.** `JSON_THROW_ON_ERROR`, `Content-Type: application/json`. The widget renders
  all text with `textContent`, never `innerHTML`, and its page sends a Content-Security-Policy and
  `X-Content-Type-Options: nosniff`. CSV cells starting with `=`, `+`, `-`, `@`, tab or CR get a
  leading `'`.
- **Abuse limits.** Per-IP limit on `/convert` and `/bot/message` (`ratelimit.per_minute`, stored in
  SQLite) → `RATE_LIMITED` (429). `X-Forwarded-For` is trusted only when `TRUSTED_PROXY` is set and
  matches the peer. CORS on `/bot/message` only for `cors.allowed_origins`.
- **Request log and blocking (ADR 0016).** Every request, including blocked ones, writes a
  `requests` row with the raw client IP; the query string is never stored. An IP over the `block.*`
  thresholds is blocked: the Kernel answers 403 `IP_BLOCKED` with `Connection: close` before
  routing. Raw IPs live only in `requests` and `ip_blocks`.
- **Rounding.** Only `InstallmentCalculator::display()` rounds money or rates;
  `Metrics/ReportFormatter` may format report aggregates. Nowhere else, including the widget JS.
- Tests first for validators, `AmountParser`, installment math and display. PHPUnit stays green.

### 3a. Money: minor units, splitting and display

- `Convert/MinorUnits` holds a static ISO 4217 exponent table (JPY 0, CLP 0, BRL 2, COP 2, KWD 3,
  BHD 3, OMR 3, CLF 4, …). A test asserts it covers every code in the fallback currency list; an
  unknown code uses 2 and records `meta.minor_units_default=true`.
- `converted = amount_usd × rate`, a PHP `float`, never rounded in the API. Storage uses integer
  fixed units instead of floats (§5, ADR 0017). Set `ini_set('serialize_precision', '-1')` at
  bootstrap. Integer minor units are exact while `total_m < 2^53` (≈ 9.0 × 10^15). The largest
  real case on the 2026-10-21 table is 1,000,000 USD in IRR (rate ≈ 1,731,936.86, 2 minor units):
  ≈ 1.73 × 10^14, about 50× below the limit. P1-05 must still check `total_m < 2^53` and reject an
  amount that would exceed it (`VALIDATION_AMOUNT`), since rates can grow.
- `InstallmentCalculator::display(converted, n, currency)`:
  - `e = MinorUnits::of(currency)`, `scale = 10^e`
  - `total_m = (int) round(round(converted, e) × scale)` (half away from zero; the inner `round`
    handles `x.xx5` float drift)
  - if `total_m < n` → reject with `VALIDATION_INSTALLMENTS` ("too many installments for this
    amount")
  - `base_m = intdiv(total_m, n)`, `last_m = base_m + total_m % n`
  - `even = (last_m === base_m)`, `regular_count = even ? n : n − 1`
  - all strings are built from the integers (integer part with `,` thousands grouping, `e`
    fraction digits, no decimal point when `e = 0`), never by casting floats
  - `display.rate`: the rate with up to 6 decimals, trailing zeros trimmed
  - `display.amount_usd`: the canonical USD string with 2 decimals
- Examples: 100 / 3 at rate 1 (USD) → 33.33, 33.33, 33.34. 200 / 3 at rate 1 → 66.66, 66.66,
  66.68. 1.00 / 60 → 0.01 × 59 and a final 0.41. 0.50 / 60 → rejected (50 minor units < 60).
  JPY 30,074 / 4 → 7,518 × 3 and a final 7,520.
- Required tests: n = 1; exact splits; remainders; n = max; `x.xx5` cases; JPY, KWD, CLF; amounts
  near the cap; reject below one minor unit; displayed installments always sum to the displayed
  total and are never zero or negative.

### 3b. Upstream: ExchangeRate-API

- `Rates/ExchangeRateClient` calls `GET /v6/{key}/latest/USD`: one call refreshes every rate. It
  runs only on a cache miss, i.e. when `now ≥ next_update_unix` — about 30 calls a month,
  regardless of traffic. `/codes` is called only to refresh currency names; `/quota` only for the
  admin health view, cached `health.quota_cache_minutes`.
- Timeout 4 s. Check `"result":"success"` and validate types (`conversion_rates` is a map of
  3-letter codes to positive numbers) before storing.
- **Allow-list:** the currency codes in the latest stored rate table. When the cache is empty,
  `Rates/FallbackCurrencies` (static ISO list) validates codes so users still get
  `VALIDATION_CURRENCY` vs an upstream error correctly.
- **Always compute locally.** `converted = amount × rate`; upstream `conversion_result` is not used.
- **Stale fallback.** If the refresh fails and a cached table younger than
  `rates.max_stale_hours` exists, serve it with `stale: true`; otherwise return the mapped error.
  Every response carries `rate_as_of` (from `time_last_update_unix`).
- **Breaker** (`Rates/UpstreamBreaker`, state in `app_state`): after `quota-reached`, stop calling
  until the first day of the next month (UTC); after `invalid-key`, stop until an operator runs
  `php bin/upstream.php reset`. While blocked, serve stale or return `UPSTREAM_QUOTA` /
  `UPSTREAM_AUTH`.
- **Error mapping:** `unsupported-code` → `UPSTREAM_UNSUPPORTED_CODE` (400); `quota-reached` →
  `UPSTREAM_QUOTA` (503); `invalid-key`, `inactive-account` → `UPSTREAM_AUTH` (503); timeout →
  `UPSTREAM_TIMEOUT` (503); bad JSON or types → `UPSTREAM_MALFORMED` (503). Generic
  `message_for_user` only.
- **No key leaks.** Every Guzzle exception is caught inside the client and mapped to an error code;
  its message (which contains the URL and key) never reaches events, logs or responses. A test
  triggers every error path and asserts the key appears in no output.
- **No network in tests.** The client takes an injected Guzzle handler; the test bootstrap sets a
  fake key and fails any test that builds a real handler.
- Every call writes `upstream.call` with `meta.result` = `hit` | `miss` | `stale` | `blocked`.

### 3c. Amount input in the bot (`Validation/AmountParser`)

> **Refined in P1-03: the ADR 0013 amendment is authoritative.** It changes rules 1–5 below and
> the "canonical or unclear" output: markers only at the ends; inner spaces only as thousands; a
> single `.` + 3 digits is unclear; a first-group rule; more than 7 digits is invalid (`over_max`).

The bot speaks English, but users may type either separator. `AmountParser` returns a canonical
string (`^\d{1,7}(\.\d{1,2})?$`) or "unclear":

1. Strip `$`, `US$`, `USD` (any case) and spaces.
2. Anything left except digits, `.` and `,` → unclear.
3. Both `.` and `,` present: the last one is the decimal separator (1–2 digits after it); the other
   is a thousands separator with groups of exactly 3 digits. `1.000,50` and `1,000.50` → `1000.50`.
4. One kind, appearing more than once: thousands separators only, groups of 3. `1.000.000` →
   `1000000`.
5. One kind, appearing once: 1–2 digits after it → decimal (`200,5` → `200.50`); exactly 3 digits →
   thousands (`1,000` → `1000`); anything else → unclear.
6. Leading or trailing separator, empty groups, or more than 2 decimals → unclear.

"Unclear" → the bot asks for clarification ("I couldn't read that amount. Please type it like 200,
200.50 or 1.000,50."). This counts as a retry and records `bot.step` invalid with
`VALIDATION_AMOUNT` and `meta.reason=unclear`. After `bot.max_retries` the session is abandoned.
`/convert` does not use `AmountParser`; it accepts only the strict form.

**Summary of the ADR 0013 amendment:** markers only at the ends; only space, NBSP and U+202F
inside a number, as thousands in groups of 3; a single `.` followed by exactly 3 digits is unclear
while a single `,` followed by 3 digits is thousands; the first thousands group is 1–3 digits with
no leading `0`; more than 64 bytes is unclear; more than 7 integer digits is invalid (`over_max`).

## 4. Repository structure (Phase 1)

```
fx-installments/
├── CLAUDE.md, AGENTS.md, CONTEXT.md
├── memory/MEMORY.md
├── .env.example                # placeholders only: EXCHANGERATE_API_KEY, ADMIN_TOKEN, DB_DSN,
│                               # TRUSTED_PROXY, DATA_ROOT, BACKUP_ROOT
├── .gitattributes, .gitignore  # LF; ignores .env, api/var/, api/vendor/
├── Makefile                    # thin wrapper over Composer scripts
├── api/
│   ├── composer.json           # scripts: test, check, acceptance
│   ├── phpunit.xml
│   ├── public/index.php        # single entry point, tiny router
│   ├── src/
│   │   ├── Http/               # Router, Request, JsonResponse, AdminAuth, RateLimiter, Cors
│   │   ├── Validation/         # Validators, AmountParser, CurrencyResolver (synonyms, countries)
│   │   ├── Rates/              # ExchangeRateClient, RateCache, UpstreamBreaker, FallbackCurrencies
│   │   ├── Convert/            # ConvertService, InstallmentCalculator, MinorUnits, CurrencyCountryMap
│   │   ├── Metrics/            # Metrics, ErrorCodes, ReportQuery, ReportFormatter, CsvWriter
│   │   ├── Config/             # ConfigRepository (read-only), defaults.php
│   │   ├── Db/                 # Database factory, Migrations
│   │   └── Flow/               # FlowDefinition (YAML loader), FlowEngine, SessionStore
│   ├── flows/default.yaml
│   ├── db/schema.sql
│   ├── bin/migrate.php, report.php, bot.php, upstream.php, ip.php
│   ├── resources/widget/       # vanilla JS, no build step
│   ├── var/                    # git-ignored: app.db (outside public/)
│   └── tests/Unit, tests/Acceptance
└── docs/
    ├── PLAN.md, TODO.md, LOG.md, GUARDRAILS.md, adr/
    └── README.md, INSTALL_LOCAL.md, REPORTS.md
```

Later phases add `src/Normalize/` (P2), `src/Capabilities/`, `resources/dashboard/`,
`bin/rollup.php`, `docker-compose.yml`, `api/Dockerfile`, `typebot/` and `docs/INSTALL_DOCKER.md`,
`docs/CAPABILITIES.md`, `docs/ADDING_A_FEATURE.md` (P3).

`DATA_ROOT` and `BACKUP_ROOT` stay as open placeholders in `.env.example`. Tests and mocks point
them at temporary directories; GR-001 still applies to real values.

## 5. Data model (SQLite, PDO — keep SQL portable)

All timestamps are UTC epoch integers; JSON columns are TEXT; no column stores a float (ADR 0017).
SQLite runs with `PRAGMA journal_mode=WAL` and `busy_timeout=5000`.

Phase 1 tables:

- `events(id, ts, source 'api'|'bot'|'upstream'|'llm', channel 'static'|'cli'|'typebot'|NULL,
  event_type, session_id, step, currency, country, amount_usd_cents, installments,
  status 'ok'|'error', error_code, latency_ms, meta)` — append-only source of truth;
  `amount_usd_cents` is an exact integer. `country` is set only when the user typed a country
  (e.g. "Brazil"); a bare code like `EUR` leaves it NULL.
- `rate_cache(currency, rate_scaled, last_update_unix, next_update_unix, fetched_at)` — one row per
  code, all rows written by one `/latest/USD` refresh; `rate_scaled = round(rate × 10^10)`, built
  from the rate's decimal text with integer arithmetic (ADR 0017 amendment).
- `sessions(session_id, channel, step, status 'open'|'completed'|'abandoned', collected, retries,
  created_at, updated_at, expires_at)`
- `app_state(key, value, updated_at)` — breaker state, cached quota.
- `rate_limits(ip, window_start, count)`
- `requests(id, ts, ip, method, path, status, latency_ms, blocked)` — one row per request (ADR
  0016); `path` truncated to 200 characters, no query string.
- `ip_blocks(ip, reason, source 'auto'|'manual', blocked_at, expires_at)` — `expires_at` NULL only
  for manual blocks.

Phase 3 adds `config(key, value, updated_at)` and `daily_rollups(...)`. The currency-country map is
a PHP array (`Convert/CurrencyCountryMap`), not a table.

**Retention:** `bin/migrate.php --prune` deletes `rate_limits` rows older than a day, closed
`sessions` older than 30 days, `requests` rows older than `requests.retention_days` (30) and
expired `ip_blocks`. `events` are kept.

Event types (Phase 1): `upstream.call`, `api.request`, `bot.session_start`, `bot.step`
(valid/invalid), `bot.session_completed`, `bot.session_abandoned`, `error`. Phase 2 adds
`llm.call`; Phase 3 adds `config.changed`.

Error codes (stable strings) and HTTP status:

| Code | HTTP |
|---|---|
| `VALIDATION_AMOUNT`, `VALIDATION_CURRENCY`, `VALIDATION_INSTALLMENTS` | 400 |
| `UPSTREAM_UNSUPPORTED_CODE` | 400 |
| `UPSTREAM_TIMEOUT`, `UPSTREAM_QUOTA`, `UPSTREAM_AUTH`, `UPSTREAM_MALFORMED` | 503 |
| `ADMIN_UNAUTHORIZED` | 401 |
| `IP_BLOCKED` | 403 |
| `NOT_FOUND` | 404 |
| `RATE_LIMITED` | 429 |
| `BOT_MAX_RETRIES`, `BOT_SESSION_EXPIRED` | (bot events only) |
| `LLM_UNAVAILABLE`, `LLM_LOW_CONFIDENCE` | (Phase 2) |
| `INTERNAL` | 500 |

## 6. Endpoints

| Endpoint | Phase | Purpose |
|---|---|---|
| `GET /convert?amount&cy&installments?` | 1 | Conversion, installment amount (unrounded), `display` block, `rate_as_of`, `stale` |
| `POST /bot/message {session_id?, text}` → `{session_id, reply, buttons?, step, done}` | 1 | Static flow engine; the contract every channel uses |
| `GET /bot/widget` | 1 | Built-in chat page |
| `GET /reports?from&to&format=json\|csv` (admin) | 1 | Summary, currencies/countries, funnel by step and channel, errors by code, upstream usage (calls, hits, stale, blocked) |
| `GET /health` | 1 | Public: `status` only. Admin: quota left (cached), cache age, DB writable, breaker state |
| `POST /normalize {text, expecting}` | 2 | LLM extraction → validated fields or `ok:false` |
| `POST /events` (per-channel ingest token) | 3 | External channels push funnel events |
| `GET /config`, `PUT /config` (admin) | 3 | Runtime settings, validated per key; every change logged |
| `GET /capabilities` | 3 | Catalog for non-devs; rendered to `docs/CAPABILITIES.md` |
| `GET /reports/dashboard` (admin) | 3 | HTML + vendored Chart.js |

Config keys (read-only defaults in `Config/defaults.php` during Phases 1–2):
`installments.default=1`, `installments.max=60`, `amount.max=1000000`, `bot.max_retries=3`,
`bot.session_ttl_minutes=30`, `bot.frontend=static`, `rates.max_stale_hours=48`,
`ratelimit.per_minute=30`, `cors.allowed_origins=[]`, `reports.timezone=America/Bogota`,
`health.quota_cache_minutes=60`, `llm.enabled=false`, `llm.confidence_threshold=0.8`,
`requests.retention_days=30`, `block.window_minutes=10`, `block.rate_limit_hits=5`,
`block.error_responses=100`, `block.duration_minutes=60` (the `block.*` values are first guesses,
to be tuned against real traffic).

## 7. User interfaces for the MVP

1. **CLI** — `php bin/bot.php` (interactive; also pipeable for scripted tests), `channel=cli`.
2. **Built-in web widget** — `GET /bot/widget`, vanilla JS, buttons for shortcuts (top
   currencies, "Just one payment"), `channel=static`. Embeddable with one `<script>` (needs the
   embedding origin in `cors.allowed_origins`) or an iframe (`frame-ancestors 'self'` plus the same
   origins).
3. **Raw endpoint** — `POST /bot/message`, the contract for any future channel.

Plus `/convert` via curl, and reports via `/reports` and `bin/report.php` (table/csv/json).

## 8. Postgres later (L-03), portable now

Phase 1 stays Postgres-ready without building Postgres support:

- All DB access through PDO behind the `Database` factory reading `DB_DSN`, `DB_USER`, `DB_PASS`.
- Portable SQL only: no SQLite-only functions, no JSON functions in queries (decode `meta` in PHP),
  epoch integers instead of date functions, upserts written as select-then-insert/update inside a
  transaction.
- L-03 adds `Dialect` (`SqliteDialect` / `PostgresDialect`), `db/schema.pgsql.sql` from the same
  migration list, a separate `fx_metrics` database in the Docker stack, and
  `tests/Integration/PostgresTest.php` gated on `TEST_PG_DSN`.

## 9. Static flow (MVP)

`flows/default.yaml` is data, not code, and the **only** source of bot wording:

```yaml
name: fx_installments
max_retries: ${config.bot.max_retries}
steps:
  - id: greet
    say: "Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency."
  - id: amount
    ask: "What was the total price in USD?"
    expecting: amount
    retry: "Sorry, that doesn't look like an amount. Please type the total in USD, for example 200 or 200.50."
    clarify: "I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50."
  - id: currency
    ask: "Which currency or country should I convert to?"
    expecting: currency
    buttons: [BRL, COP, MXN, EUR]
    retry: "I don't know that currency. Try a code like BRL or a country like Brazil."
    clarify: "Several currencies use that name or symbol. Please type the currency code, like MXN or COP."
  - id: installments
    ask: "How many monthly installments? (default 1)"
    expecting: installments
    optional: true
    buttons: ["Just one payment"]
    retry: "Please type a whole number from 1 to {{config.installments.max}}."
  - id: result
    call: convert
    say_single: "{{display.amount_usd}} USD is about {{display.converted}} {{currency}} today (rate {{display.rate}}, as of {{display.rate_as_of}}). That's a single payment of {{display.converted}} {{currency}}."
    say_even: "{{display.amount_usd}} USD is about {{display.converted}} {{currency}} today (rate {{display.rate}}, as of {{display.rate_as_of}}). That's {{installments}} monthly payments of {{display.installment}} {{currency}}."
    say_uneven: "{{display.amount_usd}} USD is about {{display.converted}} {{currency}} today (rate {{display.rate}}, as of {{display.rate_as_of}}). That's {{display.regular_count}} monthly payments of {{display.installment}} {{currency}} and a final payment of {{display.last_installment}} {{currency}}, so the total is exactly {{display.converted}} {{currency}}."
    stale_note: "Rates couldn't be refreshed just now, so this uses the last known rate."
```

Engine rules:

- Template choice: `installments = 1` → `say_single`; `display.even` → `say_even`; otherwise
  `say_uneven`. Append `stale_note` when `stale` is true. Money in templates comes only from
  `display.*`.
- Parsing: amount via `AmountParser` + validators (§3c); currency via regex + allow-list + a static
  synonym/country table (`Brazil`, `reais`, `R$` → `BRL`; English and Spanish names). Empty input
  or "Just one payment" on the optional step → `installments.default`.
- If `convert` returns `VALIDATION_INSTALLMENTS`, the engine re-asks the installments step with the
  API's `message_for_user`; this counts as a retry. Upstream errors end the session with the API's
  `message_for_user` and `bot.session_abandoned` carrying the error code.
- Sessions: ids are issued by the server (`bin2hex(random_bytes(16))`); an unknown or expired id
  starts a new session. On every `/bot/message` and before every report, open sessions past
  `expires_at` are closed as `bot.session_abandoned` with `BOT_SESSION_EXPIRED` and their last
  step. Max retries → `bot.session_abandoned` with `BOT_MAX_RETRIES`. All events carry `channel`.

## 10. Phases and tasks

IDs follow `CLAUDE.md`: commit subject `<ID>: <imperative summary>`, one commit per ID, tests in
the same commit. Each task ends with DONE, DONE_WITH_CONCERNS, BLOCKED or NEEDS_CONTEXT.
**Acceptance:** each task's `.task` file sets `acceptance=` to the listed test path, run with
`make acceptance TEST=<path>`; the observable outcome must also hold when checked by hand.
Phase 1 runs solo (one writing session at a time); the lead ticks `docs/TODO.md` on merge.

### Phase 0 — Pre-work (`CLAUDE.md` git rules were relaxed until P0-04 closed)

- **P0-01** Adapt `CLAUDE.md` to this project. *DONE 2026-10-20.*
- **P0-02** Create `CONTEXT.md`, `memory/MEMORY.md`, `docs/TODO.md`, `docs/LOG.md`,
  `docs/GUARDRAILS.md` and the initial ADRs. *DONE 2026-10-20.*
- **P0-03** Install prerequisites: PHP 8.3 with `pdo_sqlite`, `curl`, `mbstring`, `openssl`;
  `curl.cainfo` and `openssl.cafile` in `php.ini` pointing to a CA bundle (otherwise every HTTPS
  call fails on Windows); Composer; git; make. Record versions in `docs/LOG.md`.
  *Acceptance:* `php -v` shows 8.3; `php -m` lists the four extensions;
  `php -r "echo file_get_contents('https://example.com') ? 'ok' : 'fail';"` prints `ok`.
  *DONE_WITH_CONCERNS 2026-10-20 (Composer pubkeys not installed; see `docs/LOG.md`).*
- **P0-04** Owner creates the repository and remote. Then: `.gitattributes`, `.gitignore`,
  `api/composer.json` (PHPUnit as require-dev; scripts `test`, `check`, `acceptance`),
  `api/phpunit.xml`, one smoke test, thin `Makefile` (`init`, `check`, `test-guards`,
  `acceptance`), git hooks, branch protection on `main`. Delete the Phase 0 deviation from
  `CLAUDE.md` (owner-approved, done in the P0-04 close-out docs PR).
  *Acceptance:* `make init && make check` passes on a fresh clone; a direct push to `main` is
  rejected.
  *DONE 2026-10-20 (PR #1). `main` requires a PR and the `check` status check, admins included.*
- **P0-05** Owner adds `EXCHANGERATE_API_KEY` and `ADMIN_TOKEN` to `.env`.
  *Acceptance:* owner confirms; agents don't read `.env`.
  *DONE 2026-10-20: owner added `EXCHANGERATE_API_KEY` and set `ADMIN_TOKEN`.*
- **P0-06** Guard hardening follow-ups from the P0-04 review (lead proposed; owner approved):
  - `install-hooks.php` never overwrites a `.bak` that lacks the managed-hook marker; use a
    timestamped name instead (`<hook>.bak.<YYYYMMDD-HHMMSS>`).
  - `commit-msg` honours `core.commentChar` (default `#`) and always rejects a message whose first
    non-blank line starts with the comment character (a hook can't tell which cleanup mode was
    used, so this covers `--cleanup=verbatim`).
  - Guard test fixtures ignore the developer's git config (`GIT_CONFIG_NOSYSTEM=1`,
    `GIT_CONFIG_GLOBAL` pointing at an empty file).
  - Remove the unused `GuardTestCase::pathWith()`.
  - Pin `actions/checkout` and `actions/cache` to commit SHAs; fix the `setup-php` comment to the
    real tag `2.40.0`.
  *Acceptance:* `api/tests/Guards/` has a test for each behaviour change; `make check` and CI pass;
  a fresh `make init FORCE=1` over a foreign hook, followed by a managed-hook update, still leaves
  the foreign hook in a `.bak` file; `git grep pathWith -- api` finds nothing; every `uses:` in
  `.github/workflows/check.yml` is a 40-character SHA followed by a `# <real tag>` comment.
  *DONE 2026-10-20 (PR #3). Builder reported DONE_WITH_CONCERNS; the concerns were settled by the
  owner's decision to keep "always reject a comment first line", and the rest moved to L-04.*

### Phase 1 — Deterministic MVP

- **P1-01** Foundation: `public/index.php` router and envelope, `NOT_FOUND`, `INTERNAL` handler;
  `AdminAuth` (header, `hash_equals`, fail closed); `RateLimiter`; `Cors`; `Db/Database` (PDO, WAL,
  busy timeout); `Metrics` writer (failures swallowed) and `ErrorCodes`; read-only
  `ConfigRepository`; bootstrap (`serialize_precision`, `JSON_THROW_ON_ERROR`); test bootstrap with
  fake key and no-network guard.
  *Acceptance:* `tests/Acceptance/P1_01_FoundationTest.php` — unknown route → 404 envelope with
  `NOT_FOUND`; admin route with no or wrong token → 401, and with `ADMIN_TOKEN` empty → 401 even
  for an empty header; 31st request in a minute from one IP → 429 `RATE_LIMITED`; a metrics write
  against a read-only DB doesn't fail the request.
  *DONE 2026-10-20 (PR #6). Shipped only the `events` and `rate_limits` tables, by design.*
- **P1-02** `db/schema.sql` + `bin/migrate.php` (Phase 1 tables in §5, `--prune`).
  - Add `rate_cache`, `sessions`, `app_state`, `requests`, `ip_blocks`.
  - Apply ADR 0017: `events.amount_usd` becomes `amount_usd_cents INTEGER`; `Metrics` takes cents;
    remove `Database::floatParam()` once nothing binds floats.
  - Decide and document when the schema is applied (P1-01 applies it on every first connection).
  - Index `rate_limits(window_start)` and `requests(ts)` so `--prune` doesn't scan, and
    `requests(ip, ts)` for the per-IP counts behind automatic blocking (P1-11).
  - Add a test of two connections racing in the rate limiter (the unique-clash retry path).
  *Acceptance:* `tests/Acceptance/P1_02_MigrateTest.php` — migrating an empty file creates all
  seven tables; running it twice is a no-op; `--prune` removes only expired rows in each table; no
  column has a floating-point type.
  Decisions recorded in ADRs 0019 (schema-apply policy) and 0020 (migration versioning), and in the
  ADR 0016 and 0017 amendments.
  *DONE 2026-10-21 (PR #8, two rounds). The task that adds migration 4 must also replace tests that
  hard-code version 3 and "seven tables" with `Migrations::latestVersion()` and a dynamic count,
  and make the pin test fail when a migration ships without a pin.*
- **P1-03** `Validation/`: strict amount validator, `AmountParser` (§3c), currency validator with an
  injected allow-list + `CurrencyResolver` (synonyms, countries), installments validator.
  *Acceptance:* `tests/Acceptance/P1_03_ValidationTest.php` — table of inputs: `1e3`, `NaN`, `-5`,
  `0`, `200.505` rejected; `200,5`→`200.50`, `1.000,50`→`1000.50`, `1,000`→`1000`, `1,2,3`→unclear;
  `brazil`→`BRL` with country `Brazil`; `reais`, `R$`→`BRL` with no country (a country is recorded
  only when one was typed); `eur`→`EUR` with no country; `61` installments rejected. (`200.505` is
  rejected by the strict validator; in the bot it is unclear, ADR 0013 amendment.)
  *DONE 2026-10-21 (PR #10, three rounds). Leading zeros: accepted, canonical rebuilt from cents
  (ADR 0013 amendment).*
- **P1-04** `Rates/`: `ExchangeRateClient` (`/latest/USD`, `/codes`, `/quota`), `RateCache`,
  `UpstreamBreaker`, `FallbackCurrencies`, error mapping, exception scrubbing, `bin/upstream.php
  reset`, `upstream.call` events.
  *Acceptance:* `tests/Acceptance/P1_04_RatesTest.php` (mocked handler) — two conversions in one
  refresh window make one upstream call; refresh failure with a cached table serves `stale: true`;
  `quota-reached` blocks calls until next month; every error path's output contains no key.
  Also: store rates as `rate_scaled` (ADR 0017); extend the no-network guard to curl and Guzzle
  (ADR 0018). Pass upstream's decimal text to `ScaledRate::fromDecimalString` where available; a
  rate `ScaledRate` rejects drops that one currency (logged), not the whole refresh. Fix the
  `ScaledRate` docblock: upstream numbers round-trip exactly for 15 or fewer significant digits,
  not 17. Implement `Validation\CurrencyAllowList` from the rate table and `FallbackCurrencies`.
  Decide what bare English words that are also codes should do once every code is allowed (`all`,
  `top`, `cup`, `try` resolve as ALL, TOP, CUP, TRY; `won` gives `not_allowed`): the existing
  shadowing test checks table entries only, so either add such words to the ambiguous list or
  accept them as codes, and test the choice against the real list.
- **P1-11** Request log and IP blocking (ADR 0016). Runs after P1-04 and before P1-05, so
  `/convert` is protected from its first release.
  - A request-log step that writes one `requests` row for every request, including blocked ones.
  - A block check in the Kernel, before routing: 403 `IP_BLOCKED`, `Connection: close`, no handler,
    and no `error` event (the `requests` row with `blocked = 1` is the only write).
  - Automatic blocking from the `block.*` thresholds; `bin/ip.php block|unblock|list`. Block rows
    follow the ADR 0016 amendment: active while `now < expires_at`; select-then-write in one
    transaction; a manual block replaces an automatic one, never the reverse while active; a new
    automatic block on an active automatic one extends it to the later expiry.
  - `IP_BLOCKED` added to `ErrorCodes`; `requests` and expired `ip_blocks` pruned (P1-02 tables).
  *Acceptance:* `tests/Acceptance/P1_11_RequestLogTest.php` — every request, including 404s, 429s
  and blocked ones, writes exactly one `requests` row with the client IP (the trusted-proxy XFF hop
  when configured), method, path without query and status; the configured number of 429s blocks
  the IP, and so does the configured number of error responses; a blocked IP gets 403 `IP_BLOCKED`
  with `Connection: close`, its handler never runs and no `error` event is written; the block
  expires after `block.duration_minutes`; `bin/ip.php unblock` restores access; rows older than 30
  days are pruned.
- **P1-05** `Convert/`: `MinorUnits`, `InstallmentCalculator` (§3a), `ConvertService` (local
  math, `rate_as_of`, `stale`) → `GET /convert`.
  *Acceptance:* `tests/Acceptance/P1_05_ConvertTest.php` — `amount=200&cy=BRL&installments=3` at
  mocked rate 5.2291 → display `1,045.82` / `348.60` / `348.62`, `even: false`;
  `amount=1&cy=USD&installments=60` passes and `amount=0.50&cy=USD&installments=60` returns
  `VALIDATION_INSTALLMENTS`; JPY shows no decimals; KWD shows three; `amount=1e3` →
  `VALIDATION_AMOUNT`; an injected rate that makes the minor-unit total reach 2^53 →
  `VALIDATION_AMOUNT` (checked in `ConvertService`, before `display()`; §3a).
- **P1-06** `Flow/`: YAML loader, `FlowEngine` (§9 rules), `SessionStore` (server ids, TTL, expiry
  sweep), `POST /bot/message`, `bin/bot.php`.
  *Acceptance:* `tests/Acceptance/P1_06_FlowTest.php` — a scripted CLI session (`200` → `brazil` →
  `3`) ends with the uneven message and `bot.session_completed`; three unclear amounts →
  `BOT_MAX_RETRIES` abandon at step `amount`; an expired session is recorded as
  `BOT_SESSION_EXPIRED` at its last step.
  Also (from the P1-03 review): use `AmountParser::read()` (ok / unclear / invalid) and give unclear
  and invalid amounts different wording; use the currency step's `clarify` text for ambiguous
  currency text; consider an `InstallmentsReading` result type so all three validators return the
  same shape; widen the ambiguous list (`won`, `dirham`, `shilling`, `lira`, `krona`, `rials`,
  `riyals`, `leu`, `manat`, `som`) and country coverage (Uruguay, Bolivia, Paraguay, Venezuela,
  Central America, the Dominican Republic, Puerto Rico, Canada; `yuan` → CNY); document `£` → GBP
  while `pound` stays ambiguous; `normalize()` may return more than 7 integer digits, so call
  `read()`; `ValidationFailed::messageForUser()` is the generic `ErrorCodes` text, so bot wording
  comes from the flow YAML.
- **P1-07** `resources/widget/` at `GET /bot/widget`, `channel=static`, `textContent` only, CSP,
  `nosniff`, `frame-ancestors`.
  *Acceptance:* `tests/Acceptance/P1_07_WidgetTest.php` — response has the CSP and `nosniff`
  headers; widget JS contains no `innerHTML`, `toFixed` or `Intl.NumberFormat`; by hand, a
  `<script>` typed into the chat shows as text.
  Also: remove `X-Powered-By` from every response; make the `Authorization: Bearer` parsing strict
  (no trailing newline accepted).
- **P1-08** `Metrics/ReportQuery`, `ReportFormatter`, `CsvWriter` → `GET /reports` (json/csv) and
  `bin/report.php` (table/csv/json); `GET /health` (public and admin views).
  *Acceptance:* `tests/Acceptance/P1_08_ReportsTest.php` — over seeded events, funnel counts per
  step and channel match; days split on `reports.timezone`; a currency named `=CMD()` is escaped in
  CSV; public `/health` shows only `status`.
  Also: decide what `/health` does when the database is behind or can't be opened (ADR 0019): treat
  the 500 as "unhealthy", or route `/health` before the database opens.
- **P1-09** `db/seed_demo.php`; `docs/README.md` (incl. a privacy note on the request log: raw
  IPs, 30-day retention, blocks; ADR 0016), `docs/INSTALL_LOCAL.md` (incl. CA bundle setup, and
  the ADR 0019 operations: run `php api/bin/migrate.php` after `composer install` and on every
  pull; a 500 with `SchemaOutOfDate` in the log means "run migrate"; one migrate at a time; back up
  `api/var/app.db` first; a daily `--prune` job; `seed_demo` runs after migrate and writes cents;
  what `migrate.php` prints and how to repair on `SchemaCorrupt`; that `ScaledRate::fromRate`
  needs `ini_set`, which must not be in `disable_functions`), `docs/REPORTS.md`. Add an acceptance
  test that the CLI exits 1 on a corrupt schema version.
  *Acceptance:* following `INSTALL_LOCAL.md` on a clean checkout reaches a working `/convert` with
  demo data, without Docker.
- **P1-10** End-to-end run: CLI and widget against the real key, then `/reports`. Log quota used in
  `docs/LOG.md`. **Deliver MVP.**
  *Acceptance:* owner sees one completed session per channel in `/reports`, and quota used is ≤ 3
  calls.

### Phase 2 — LLM normalizer (feature-flagged)

- **P2-01** `Normalize/`: `LlmClient` interface, extraction schema
  `{amount, currency, installments, confidence}`, confidence threshold, fallback to the
  deterministic parser. Extracted amounts go through `AmountParser` and the validators.
- **P2-02** Adapters: `OllamaAdapter` (default, `OLLAMA_URL`, `LLM_MODEL`), `AnthropicAdapter`,
  `OpenAiAdapter`; selected by `LLM_PROVIDER`. `llm.call` events: provider, model, tokens,
  latency, fallback flag.
- **P2-03** `POST /normalize`; `FlowEngine` uses it only when `llm.enabled=true`. Tests with mocked
  adapter, including prompt-injection inputs.
- **P2-04** Reports: LLM section (usage, est. cost, fallback rate, completion rate with vs
  without).
- **P2-05** `/health` admin view checks LLM reachability.

Acceptance paths for Phase 2 and 3 are set when each phase starts, in the same format.

### Phase 3 — Typebot, Docker and operator extras

- **P3-01** `docker-compose.yml`: `api` + `--profile typebot` (builder, viewer, postgres) +
  `--profile ollama`; `api/Dockerfile` (`php:8.3-fpm-alpine` + nginx); SQLite on a named volume;
  `docs/INSTALL_DOCKER.md`. nginx reads a deny list exported from `ip_blocks` and returns 444 to
  blocked IPs, closing the connection without a response; nginx's access log for those requests is
  imported into `requests` with `blocked = 1`, so INV-08 still holds (ADR 0016).
- **P3-02** Config API: `config` table, `GET /config`, `PUT /config` with per-key type and range
  checks, `config.changed` events.
- **P3-03** `POST /events`: per-channel ingest token, allow-listed `event_type`/`channel`/`step`,
  `meta` size cap, rate limit.
- **P3-04** `GET /capabilities` and generated `docs/CAPABILITIES.md`.
- **P3-05** Typebot flow with HTTP Request blocks against catalog endpoints; `{{resultId}}` as
  `session_id`; `channel=typebot`; money only from `display.*`. Export to
  `typebot/flows/fx_installments.json` + sub-flow templates (validate-or-retry).
- **P3-06** Loader snippet reading `config.bot.frontend`; `/health` checks the Typebot viewer for
  automatic fallback to the static widget.
- **P3-07** Contract test: every HTTP block in the export maps to a catalog endpoint/field.
- **P3-08** `GET /reports/dashboard` with Chart.js vendored under `resources/dashboard/`.
- **P3-09** `daily_rollups` + `bin/rollup.php`, scheduled by a cron service in the compose stack;
  reports switch to rollups for past days.
- **P3-10** APCu read-through cache on top of `rate_cache`.
- **P3-11** `docs/ADDING_A_FEATURE.md` (no-code track vs new-capability track).

### Later / stretch

- **L-01** `bin/export-typebot.php` — starter Typebot JSON from the YAML.
- **L-02** Telegram / WhatsApp webhook channel for the static flow.
- **L-03** Postgres metrics: `Dialect`, `db/schema.pgsql.sql`, `PostgresTest` (§8). Translate
  dialect when applying migrations, never by editing shipped ones (ADR 0020); the rebuilt `events`
  table's primary-key constraint name and `INTEGER PRIMARY KEY` need translating. Test the `42P01`
  (missing `app_state`) branch of `Migrator::currentVersion()`, and treat a stored version `"0"`
  as corrupt once `app_state` exists.
- **L-04** Tooling backlog from the P0-06 review:
  - Move `actions/cache` to v5 (v4 runs on the deprecated Node 20 runtime; CI warns).
  - Write hooks atomically in `install-hooks.php` (temp file + rename), so a failed write can't
    leave a truncated hook.
  - `make init FORCE=0` should not force (`$(if $(FORCE),…)` treats any non-empty value as true).
  - Decide how `git merge --squash` commits get a valid subject (git's default "Squashed commit of
    the following:" is rejected by `commit-msg`).
  - Optionally prune managed hook backups (those carrying the marker).
  - Compare `TRUSTED_PROXY` and `REMOTE_ADDR` as parsed addresses (`inet_pton`), so two spellings
    of the same IPv6 address match.
  - Optionally compare admin tokens through HMACs, so the comparison doesn't reveal token length.
  - `Metrics` lets a bool through in a text column; reject it.
  - `RateLimiter::isUniqueViolation` treats any SQLSTATE 23000 (CHECK, NOT NULL) as a key clash,
    costing one wasted retry; match the unique-violation error only.
  - `Database::connect` sets `PRAGMA journal_mode=WAL` before the schema check, so opening an empty
    file writes to it (no tables are created); check the schema before changing the journal mode.
  - The `ScaledRate` sweep calls `mt_srand` with a fixed seed; reseed in `tearDown` so later tests
    don't inherit the RNG state.
  - (P1-03) `ConfigRepository` accepts `amount.max` ≤ 0 (every amount then fails `over_max`);
    reject it at load time.
  - (P1-03) The `AmountParser` sweep checks values but not swaps of one accepted input for another;
    the pinned `SWEEP_*_READ` counts must change only with a rule change, explained in the commit.
  - (P1-03) `TextNormalizer` folds only precomposed Latin accents; decomposed forms (a letter plus
    U+0301) fall through to `unknown`. Normalize to NFC if intl becomes available.

## 11. Working agreements for the agent

- Follow `CLAUDE.md` for all development rules; this plan defines *what* to build.
- Work phase by phase, in the listed order; don't start Phase 2 code before Phase 1 is delivered
  and green.
- Keep `docs/CAPABILITIES.md` generated, never hand-edited (Phase 3).
- When a decision is unclear, ask the owner.
- Report progress and anomalies (quota usage, upstream errors, test failures) in `docs/LOG.md`,
  dated (YYYY-MM-DD, America/Bogota).
- Record each new or changed decision as an ADR in `docs/adr/` (context, decision,
  consequences), in the same commit as the first code that depends on it.

## 12. Decision record

All decisions, including the pre-work answers and the review changes of 2026-10-20, are in
`docs/adr/` (index: `docs/adr/README.md`). The session history is in `docs/LOG.md`.
