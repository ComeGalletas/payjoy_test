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
places (`1045.8200000000002`), and a whole value prints without a fraction (`200`). Tests assert
on `display` strings or compare raw numbers with a tolerance, never on exact float JSON. `rate_as_of`
is ISO 8601 UTC. In `display`, every field is a string except `regular_count` (int) and `even`
(bool); the money strings are grouped, including `amount_usd` (`1,000,000.00`), and exact for the
stored rate (ADR 0011 amendment). A stale answer has the same shape with `stale: true`.

Everything must be **measurable**: ExchangeRate calls, cache hits and stale serves, currencies and
countries requested, bot sessions (started / completed / abandoned, with the step), every error by
code, with easy reports.

## 2. Decisions (final)

Reasons and consequences are in the ADR named in each row.

| Topic | Decision | ADR |
|---|---|---|
| Language | PHP 8.3, no framework. Composer: `guzzlehttp/guzzle` (`^8.3`), `vlucas/phpdotenv`, `symfony/yaml` (`^7.4`); `phpunit/phpunit` as require-dev. | 0001 |
| Database | SQLite via PDO behind a `Database` factory, portable SQL, UTC epoch integers, JSON as TEXT. Postgres support (`Dialect`, second schema) deferred to L-03. | 0010 |
| Chatbot MVP | Static flow in YAML, run by a PHP state machine. **No LLM in the MVP.** Deterministic parsing only. Contract, sessions and retries in ADR 0021. | 0003, 0021 |
| LLM | Phase 2, feature-flagged normalizer in front of the deterministic validators. Never replaces validation. | 0003 |
| Typebot | Phase 3, optional front-end calling the same endpoints. Static flow stays as fallback. | 0004 |
| Deployment | Local install without Docker is primary through Phase 2 (PHP 8.3, Composer, `php -S`). Docker compose arrives in Phase 3. | 0005 |
| Amount semantics | `amount` is the **total** USD price. `installment_amount = converted / installments`. | 0006 |
| Money | Full precision in the API. Splitting and display work in the currency's **ISO 4217 minor units**; round down, the **last installment absorbs the remainder**; reject splits under one minor unit. Display strings are English-formatted. See §3a. | 0011 |
| Storage of money | No floats in the database: USD amounts as integer cents, rates as integers scaled by 10^10. | 0017 |
| Request log | Every request is recorded with its raw client IP (kept 30 days); abusive IPs are blocked automatically. | 0016 |
| Demo data | `api/db/seed_demo.php` writes fixed report demo data, with no stored rate table, into a throwaway database. | 0024 |
| Reports | `/reports` and `bin/report.php` share one query: a cohort funnel, labels checked against label allow-lists (anything else is `other`), integer money, a formula-safe long-format CSV. | 0023 |
| Widget | Built-in `static` front-end at `/bot/widget`: lazy open, strict CSP with Trusted Types, embeddable by iframe or `<script>`. | 0022 |
| Test isolation | Tests can't reach the network: `-d allow_url_fopen=0` in the gate scripts plus a stream-wrapper guard; from P1-04 also `disable_functions=curl_exec,curl_multi_exec` and Guzzle handler stubs. | 0018 |
| Upstream | One `/latest/USD` call per upstream refresh; always compute `amount × rate` locally; stale fallback; stop calling after quota or key errors, and back off 1 to 60 minutes after any other failed refresh. See §3b. | 0012 |
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
  `ok` and display `message_for_user` verbatim. The widget's files (page, script, style sheet,
  icon), the 204 `/favicon.ico` and the CSV report are the only non-envelope responses (ADR 0022,
  ADR 0023).
- **Every** meaningful action writes an event through the single `Metrics` class. No ad-hoc
  logging. A failed metrics write is caught and never fails the user's request.
- **Secrets.** Only in `.env` (the owner fills it; agents never read it). The API key never appears
  in responses, events, logs or exception text (§3b). The test suite never loads the real key and
  never reaches the network.
- **Admin token.** `ADMIN_TOKEN` from `.env`, sent only in the `Authorization: Bearer` header,
  compared with `hash_equals`. If the token is unset or empty, every admin request is refused.
  Never in a URL. The header must be `Bearer` (any case), one or more spaces, then the token and
  nothing after it, so the token must be visible ASCII (ADR 0022).
- **Output safety.** `JSON_THROW_ON_ERROR`, `Content-Type: application/json`. The widget renders all
  text with `textContent`, never `innerHTML`, and its page sends a Content-Security-Policy with
  Trusted Types and `X-Content-Type-Options: nosniff`. No response carries `X-Powered-By`
  (ADR 0022).
  CSV is long format (`section,key,subkey,metric,value`). A cell holding `"`, `,`, `;`, CR or LF
  is quoted, and the cell and each `;`-separated segment get a leading `'` when they start with
  `=`, `+`, `-`, `@`, tab or CR, or with whitespace before `=`, `+`, `-` or `@` (ADR 0023).
- **Abuse limits.** Per-IP limit on `/convert` and `/bot/message` (`ratelimit.per_minute`, stored in
  SQLite) → `RATE_LIMITED` (429). `X-Forwarded-For` is trusted only when `TRUSTED_PROXY` is set and
  matches the peer. CORS on `/bot/message` only for `cors.allowed_origins`.
- **Request log and blocking (ADR 0016 and its amendments).** Every request that reaches the
  Kernel, including blocked ones, writes a `requests` row with the raw client IP unless the write
  fails (INV-07, INV-08); the query string is never stored, and path
  and method are stored percent-encoded. An IP over a `block.*` threshold is blocked: the Kernel
  answers 403 `IP_BLOCKED` with `Connection: close` before routing. `block.rate_limit_hits` counts
  rate-limited minutes, `block.error_responses` counts 4xx other than 429; 5xx and blocked
  requests never count. Loopback, the trusted proxy and non-IPs are never blocked automatically,
  and a failed block check lets the request through. Raw IPs live only in `requests`,
  `rate_limits` and `ip_blocks`; `ip.block` and `ip.unblock` events never hold them.
- **Rounding.** Only `InstallmentCalculator::display()` rounds money or rates;
  `Metrics/ReportFormatter` may format report aggregates. Nowhere else, including the widget JS.
- Tests first for validators, `AmountParser`, installment math and display. PHPUnit stays green.

### 3a. Money: minor units, splitting and display

- `Convert/MinorUnits` holds a static ISO 4217 exponent table (JPY 0, CLP 0, BRL 2, COP 2, KWD 3,
  BHD 3, OMR 3, CLF 4, …). A test asserts it covers every code in the fallback currency list; an
  unknown code uses 2 and records `meta.minor_units_default=true`.
> **Refined in P1-05: the ADR 0011 amendment is authoritative.** `total_m` is computed exactly
> from cents and `rate_scaled` with integer arithmetic, `display()` takes
> `(cents, rate_scaled, n, currency, rate_as_of)`, and `display.amount_usd` and `display.rate` are
> grouped. The float formula below is kept for history.

- `converted = amount_usd × rate`, a PHP `float`, never rounded in the API. Storage uses integer
  fixed units instead of floats (§5, ADR 0017). Set `ini_set('serialize_precision', '-1')` at
  bootstrap. Integer minor units are exact while `total_m < 2^53` (≈ 9.0 × 10^15). The largest
  real case on the 2026-10-21 table is 1,000,000 USD in IRR (rate ≈ 1,731,936.86, 2 minor units):
  ≈ 1.73 × 10^14, about 50× below the limit. P1-05 must still check `total_m < 2^53` and reject an
  amount that would exceed it (`VALIDATION_AMOUNT`), since rates can grow.
- `InstallmentCalculator::display(converted, n, currency)`:
  - `e = MinorUnits::of(currency)`, `scale = 10^e`
  - `total_m = (int) round(round(converted, e) × scale)` (half away from zero; the inner `round`
    handles `x.xx5` float drift). Replaced in P1-05 by
    `round_half_away_from_zero(cents × rate_scaled × 10^e / 10^12)` in integers.
  - if `total_m < n` → reject with `VALIDATION_INSTALLMENTS` ("too many installments for this
    amount")
  - `base_m = intdiv(total_m, n)`, `last_m = base_m + total_m % n`
  - `even = (last_m === base_m)`, `regular_count = even ? n : n − 1`
  - all strings are built from the integers (integer part with `,` thousands grouping, `e`
    fraction digits, no decimal point when `e = 0`), never by casting floats
  - `display.rate`: the rate with up to 6 decimals, trailing zeros trimmed (integer part grouped)
  - `display.amount_usd`: the USD amount with 2 decimals, grouped (`1,000,000.00`)
- Examples: 100 / 3 at rate 1 (USD) → 33.33, 33.33, 33.34. 200 / 3 at rate 1 → 66.66, 66.66,
  66.68. 1.00 / 60 → 0.01 × 59 and a final 0.41. 0.50 / 60 → rejected (50 minor units < 60).
  JPY 30,074 / 4 → 7,518 × 3 and a final 7,520.
- Required tests: n = 1; exact splits; remainders; n = max; `x.xx5` cases; JPY, KWD, CLF; amounts
  near the cap; reject below one minor unit; displayed installments always sum to the displayed
  total and are never zero or negative.

### 3b. Upstream: ExchangeRate-API

- `Rates/ExchangeRateClient` calls `GET /v6/{key}/latest/USD`: one call refreshes every rate. It
  runs only on a cache miss, i.e. when `now ≥ next_update_unix` — about 30 calls a month,
  regardless of traffic. The stored `next_update_unix` is at least `fetched_at + 300`, so a late
  upstream can't cause a call per lookup (ADR 0012 amendment). `/codes` is called only to refresh
  currency names; `/quota` only for the admin health view, cached `health.quota_cache_minutes`.
- Timeout 4 s. Check `"result":"success"` and validate types (`conversion_rates` is a map of
  3-letter codes to positive numbers) before storing.
- **Allow-list:** the currency codes in the latest stored rate table. When the cache is empty,
  `Rates/FallbackCurrencies` (static ISO 4217 list plus upstream's non-ISO territory codes)
  validates codes so users still get `VALIDATION_CURRENCY` vs an upstream error correctly.
- **Always compute locally.** `converted = amount × rate`; upstream `conversion_result` is not used.
- **Stale fallback.** If the refresh fails and a cached table younger than
  `rates.max_stale_hours` exists, serve it with `stale: true`; otherwise return the mapped error.
  Every response carries `rate_as_of` (from `time_last_update_unix`). The age is measured from
  `rate_as_of`, and a table is usable while it is strictly younger than the limit.
- **Breaker** (`Rates/UpstreamBreaker`, state in `app_state`; ADR 0012 amendment): every failed
  refresh trips it except `no_key`.
  - After `quota-reached`, stop calling until the first day of the next month (UTC).
  - After `invalid-key` or `inactive-account`, stop until an operator runs
    `php api/bin/upstream.php reset`, which records an `upstream.breaker_reset` event.
  - After any other failure, including `unsupported-code` on `/latest/USD` and a failed
    `rate_cache` write, back off for 1, 5, 10, 20, 40, then 60 minutes per consecutive failure. A
    successful refresh clears the backoff.
  - An open trip is never replaced by one of equal or lower strength (auth > quota > backoff).
  - While blocked, serve stale (`result=blocked`, `stale=true`) or return `UPSTREAM_QUOTA`,
    `UPSTREAM_AUTH`, or for a backoff the code of the failed refresh that opened it.
- **Error mapping:** `unsupported-code` → `UPSTREAM_UNSUPPORTED_CODE` (400; from P1-05, 503 while
  an `unsupported-code` backoff is open, ADR 0012 amendment item 9); `quota-reached` →
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
`VALIDATION_AMOUNT` and `meta.reason=unclear`. After `bot.max_retries` failures at one step the
session is abandoned (retries count per step, ADR 0021).
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
│   └── tests/Unit, tests/Acceptance, tests/js (the widget harness, run from PHPUnit)
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
`ip_blocks` that ended at least `block.window_minutes` ago (ADR 0016 second amendment). `events`
are kept.

Event types (Phase 1): `upstream.call`, `upstream.breaker_reset`, `ip.block`, `ip.unblock`,
`api.request`, `bot.session_start`, `bot.step` (valid/invalid), `bot.session_completed`,
`bot.session_abandoned`, `error`. Phase 2 adds `llm.call`; Phase 3 adds `config.changed`. The
`ip.*` events never hold the IP; their meta is listed per event in the ADR 0016 second amendment,
item 8.

Error codes (stable strings) and HTTP status:

| Code | HTTP |
|---|---|
| `VALIDATION_AMOUNT`, `VALIDATION_CURRENCY`, `VALIDATION_INSTALLMENTS` | 400 |
| `BAD_REQUEST` | 400 (an unreadable `/bot/message` body, ADR 0021; a bad `/reports` query, ADR 0023) |
| `UPSTREAM_UNSUPPORTED_CODE` | 400; 503 while an `unsupported-code` backoff is open (from P1-05, ADR 0012 amendment item 9) |
| `UPSTREAM_TIMEOUT`, `UPSTREAM_QUOTA`, `UPSTREAM_AUTH`, `UPSTREAM_MALFORMED` | 503 |
| `UNHEALTHY` | 503 (public `GET /health` only, when the database is behind the code, has a corrupt schema version, can't be opened or can't be written; ADR 0019 amendment) |
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
| `GET /convert?amount&cy&installments?` | 1 | Conversion, installment amount (unrounded), `display` block, `rate_as_of`, `stale`. `amount` and `cy` required; `installments` defaults to `installments.default`; a missing `amount` or `cy`, an empty value, or an array answers `format`; checked in the order amount, currency, installments; extra parameters ignored, and a repeated one keeps its last value (PHP) |
| `POST /bot/message {session_id?, text}` → `{session_id, reply, buttons?, step, done}` | 1 | Static flow engine; the contract every channel uses. A JSON object of at most 4 KiB with `Content-Type: application/json`, else 400 `BAD_REQUEST`; `buttons` only while the waiting step has them; `done` once completed or abandoned; `reply` empty only with `done` for a double-submit on a closed session (ADR 0021) |
| `GET /bot/widget` | 1 | Built-in chat page, with `/bot/widget.js`, `.css` and `.svg`; `GET /favicon.ico` answers 204 (ADR 0022) |
| `GET /reports?from&to&format=json\|csv` (admin) | 1 | Summary, days, currencies/countries, funnel by channel and step, errors by code, upstream calls per endpoint, request log by kind, IP blocks, notes. `from`/`to` are `YYYY-MM-DD` in `reports.timezone`, both included, years 1970–9998, default the last 7 days, at most 366; a bad query answers 400 `BAD_REQUEST`; `no-store`; sweeps expired sessions first (ADR 0023) |
| `GET /health` | 1 | Public: `status` only, or 503 `UNHEALTHY` when the database is unusable; `GET /health` skips the database open before routing (ADR 0019 amendment). Admin (Bearer scheme only; other schemes get the public view; a wrong or malformed Bearer token gets 401): always 200 with database, rate-table ages, breaker state with `failures`, and quota (cached `health.quota_cache_minutes`, both reset dates, no call while the database can't be written; ADR 0012 second amendment) |
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
to be tuned against real traffic). Ranges: `block.window_minutes` 1–1440,
`block.duration_minutes` 1–525600, the two counts at least 1. `block.rate_limit_hits` counts
rate-limited minutes, not 429 responses.

## 7. User interfaces for the MVP

1. **CLI** — `php bin/bot.php` (interactive; also pipeable for scripted tests), `channel=cli`.
2. **Built-in web widget** — `GET /bot/widget`, vanilla JS, buttons for shortcuts (top
   currencies, "Just one payment"), `channel=static`. Embeddable with one `<script>` (needs the
   embedding origin in `cors.allowed_origins`) or an iframe (`frame-ancestors 'self'` plus the same
   origins). It opens its session lazily, on the first focus or "Start chat", and its own UI
   strings live in `widget.js` (ADR 0022).
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

> **Refined in P1-06: ADR 0021 is authoritative.** It adds the flow keys `max_retries_reply`,
> `reasons` (per-reason texts; the conversion reasons may use `{{message_for_user}}`) and
> `upstream_error`, and the rules for the first message, per-step retries, conversion retries,
> double-submits, the sweeper and the loader. `not_in_table` re-asks the currency instead of
> ending the session (owner decision).

Engine rules:

- Template choice: `installments = 1` → `say_single`; `display.even` → `say_even`; otherwise
  `say_uneven`. Append `stale_note` when `stale` is true. Money in templates comes only from
  `display.*`.
- Parsing: amount via `AmountParser` + validators (§3c); currency via regex + allow-list + a static
  synonym/country table (`Brazil`, `reais`, `R$` → `BRL`; English and Spanish names). Empty input
  or "Just one payment" on the optional step → `installments.default`.
- If `convert` returns `VALIDATION_INSTALLMENTS`, the engine re-asks the installments step with the
  API's `message_for_user` through `reasons.below_minor_unit` (ADR 0021); this counts as a retry.
  Upstream errors end the session with the API's `message_for_user` and `bot.session_abandoned`
  carrying the error code, except `not_in_table`, which re-asks the currency (ADR 0021).
- Sessions: ids are issued by the server (`bin2hex(random_bytes(16))`); an unknown, expired or
  finished id starts a new session (ADR 0021). On every `/bot/message` and before every report, open
  sessions past `expires_at` are closed as `bot.session_abandoned` with `BOT_SESSION_EXPIRED` and
  their last step. Max retries → `bot.session_abandoned` with `BOT_MAX_RETRIES`. All events carry
  `channel`.

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
- **P0-07** Claude Code guard layer: the PreToolUse and settings layers planned in
  `docs/GUARDRAILS.md` for GR-001, GR-002, GR-004, GR-005, GR-006, GR-017 and GR-018 (lead
  proposed 2026-10-21). **Deferred by the owner:** not scheduled; the owner is reviewing the
  settings template first ("the guard hooks aren't that necessary at the moment"). The lead's
  transcript audit (LOG 2026-10-21) found no read or edit of `.env`.
  - **Step 1, `.env` deny rules** in `.claude/settings.json` (owner-owned, GR-006; the owner
    applies them): `Read(//**/.env)`, `Edit(//**/.env)`, `Read(//**/.env.local)`,
    `Edit(//**/.env.local)`, `Read(//**/.env.*.local)` and `Edit(//**/.env.*.local)` in
    `permissions.deny`, leaving `.env.example` open for placeholders; optionally
    `Edit(//**/.claude/settings.json)` and `Edit(//**/.claude/settings.local.json)`, so an agent
    can't remove the rules (a plain `.claude/…` pattern is relative to the working folder, so it
    would miss the main checkout's copy from a worktree). `Edit` rules also cover Write and
    shell redirects; `Read` rules cover Grep and Glob on a best-effort basis. They don't reliably
    stop a shell read such as `cat .env`.
  - **Step 2, a PreToolUse guard:** a PHP script, run as `php` with `args` (the exec form), that
    blocks with exit code 2 and a message stating the remedy (GR-030). The probe checks that the
    hook's shell finds `php`; if not, use its full path. It covers GR-002 shell reads of `.env`,
    GR-001 (once real data roots are set), GR-004 writes outside the caller's worktree,
    GR-005/GR-006 owner files, the lead's edit scope (`CLAUDE.md` Roles), GR-017 hook bypass flags
    and GR-018 destructive commands. Configure `"onFailure": "block"` so a crash or timeout blocks
    instead of allowing.
  - **Optional, same task:** a secret scan in `make check` (the GR-002 backstop), the
    `resources/widget/` checks of INV-01, a CI check on commit subjects, and a `CODEOWNERS` file.
  - **Feasibility (checked 2026-10-21 against the Claude Code docs, version 2.1.303):** hooks fire
    in the desktop Code tab and inside worktree subagents, whose calls carry `agent_id`,
    `agent_type` and `cwd`. So a hook can tell a subagent from the top-level session, and find its
    worktree from `cwd`, without a per-session variable; it can't tell the owner's top-level
    session from the lead's, and builders, testers and judges share one `agent_type` (see Open
    decisions). Desktop worktree sessions read hooks from the main checkout's settings (to
    confirm with a logging probe for `isolation: "worktree"` subagents). Settings reload without a
    restart. Only exit code 2 blocks; other failures and timeouts allow unless `onFailure` is
    `block` (2.1.295+).
  - **Open decisions before a builder starts:** how owner sessions are exempted (the desktop app
    can't give one session its own environment, so `AGENT_GUARD_OFF=1` in a shared settings file
    would also turn the guard off for agents); the exact role rules.
  *Acceptance (when scheduled):* guard tests in the Guards suite feed tool-call JSON to the script
  and check each block and allow; after merge, a live probe from the lead and from a worktree
  subagent tries to read a decoy `.env` in a scratch folder, write to a scratch path outside its
  worktree (never the main checkout) and run `rm -rf` on a nonexistent path inside the scratch
  folder, and each is blocked and reported, so nothing is harmed if the guard fails open. The real
  `.env` is never touched. The owner approves these probes in the brief.

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
  *DONE 2026-10-21 (PR #12, three rounds; round 3 was the lead's backoff change). Decisions in the
  ADR 0012 amendment (breaker kinds, backoff, refresh floor, stale age) and the ADR 0001 amendment
  (Guzzle 8). Code words: `CurrencySynonyms::CODE_WORDS` (`all bob cup kid mad mop sos top try`)
  are codes only when typed in capitals; `pen` and `cop` stay codes. Upstream answers are read with
  `Accept-Encoding: identity`, and any other `Content-Encoding` is `UPSTREAM_MALFORMED`. The key
  lives in `Rates\ApiKey`, which hides it from dumps, casts and serialization.*
- **P1-11** Request log and IP blocking (ADR 0016). Runs after P1-04 and before P1-05, so
  `/convert` is protected from its first release.
  - A request-log step that writes one `requests` row for every request, including blocked ones.
  - A block check in the Kernel, before routing: 403 `IP_BLOCKED`, `Connection: close`, no handler,
    and no `error` event (the `requests` row with `blocked = 1` is the only write).
  - Automatic blocking from the `block.*` thresholds; `bin/ip.php block|unblock|list`. Block rows
    follow the ADR 0016 amendment: active while `now < expires_at`; select-then-write in one
    transaction; a manual block replaces an automatic one, never the reverse while active; a new
    automatic block on an active automatic one extends it to the later expiry.
  - `IP_BLOCKED` added to `ErrorCodes`; `requests` pruned, and ended `ip_blocks` pruned one window
    after they end (P1-02 tables; ADR 0016 second amendment).
  *Acceptance:* `tests/Acceptance/P1_11_RequestLogTest.php` — every request, including 404s, 429s
  and blocked ones, writes exactly one `requests` row with the client IP (the trusted-proxy XFF hop
  when configured), method, path without query and status; the configured number of 429s blocks
  the IP, and so does the configured number of error responses; a blocked IP gets 403 `IP_BLOCKED`
  with `Connection: close`, its handler never runs and no `error` event is written; the block
  expires after `block.duration_minutes`; `bin/ip.php unblock` restores access; rows older than 30
  days are pruned.
  *DONE 2026-10-21 (PR #14, three rounds). Decisions in the ADR 0016 second amendment: 429s count
  as rate-limited minutes, not responses (owner), so "the configured number of 429s" above means
  rate-limited minutes; ended blocks are pruned one window late; `ip.block` and `ip.unblock`
  events; the 403 text; exemptions and fail-open.*
- **P1-05** `Convert/`: `MinorUnits`, `InstallmentCalculator` (§3a), `ConvertService` (local
  math, `rate_as_of`, `stale`) → `GET /convert`.
  *Acceptance:* `tests/Acceptance/P1_05_ConvertTest.php` — `amount=200&cy=BRL&installments=3` at
  mocked rate 5.2291 → display `1,045.82` / `348.60` / `348.62`, `even: false`;
  `amount=1&cy=USD&installments=60` passes and `amount=0.50&cy=USD&installments=60` returns
  `VALIDATION_INSTALLMENTS`; JPY shows no decimals; KWD shows three; `amount=1e3` →
  `VALIDATION_AMOUNT`; an injected rate that makes the minor-unit total reach 2^53 →
  `VALIDATION_AMOUNT` (checked in `ConvertService`, before `display()`; §3a).
  Also (from the P1-04 review): while a backoff started by `unsupported-code` on `/latest/USD` is
  open, every lookup without a usable stale table fails with `UPSTREAM_UNSUPPORTED_CODE`, a 400 for
  every currency. **Owner decision (2026-10-21):** while such a backoff is open (including the
  failed refresh that opens it), a lookup without a usable stale table answers 503 (ADR 0012
  amendment item 9). A currency missing from a usable table (`not_in_table`) stays 400
  `UPSTREAM_UNSUPPORTED_CODE`, so don't remap the code globally in `ErrorCodes`. *Lead suggestion,
  not owner-decided:* give the 503 a generic upstream message, not "That currency is not supported
  right now". A new error code changes the stable-string contract and needs a lead ADR note before
  merge. The builder lists the choice. `P1_05_ConvertTest` covers it: a lookup during an
  `unsupported-code` backoff, without a usable stale table, answers 503 for a valid currency, and a
  `not_in_table` lookup still answers 400.
  Also (from P1-11): blocking assumes one global `ratelimit.per_minute`; don't give `/convert` its
  own limit without updating the rate-limited-minute count (ADR 0016 second amendment).
  *DONE 2026-10-21 (PR #16, two rounds). Owner decisions in the ADR 0011 amendment: the display
  total is exact (integers from cents and `rate_scaled`), and `display.amount_usd` and
  `display.rate` are grouped. The 503 mechanism is in ADR 0012 item 9; `toRate()` in the ADR 0017
  second amendment. Round 2 also fixed the P1-04 breaker-write bug the tester found (owner
  decision). The response shape (§1), the parameter rules (§6) and the `api.request` event are
  the builder's design, accepted by the tester and judge and recorded as spec here, with no ADR.
  Every request the handler answers writes one `api.request` event: columns `amount_usd_cents`,
  `currency`, `installments` as far as validated, status, `error_code`, `latency_ms`; meta
  `endpoint=convert`, `http_status`, `stale` on success, `reason` on failure,
  `minor_units_default` when set.*
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
  Also (from the P1-04 review): decide whether Spanish or Portuguese words that are also codes
  need the capitals rule (`CurrencySynonyms::CODE_WORDS`). If Bolivia → BOB is added to
  `CurrencyCountryMap`, `CurrencyCodeWordsTest` fails until `bob` leaves `CODE_WORDS` (a mapped
  currency's code must stay a code); decide what lowercase `bob` then means.
  Also (from P1-11): blocking assumes one global `ratelimit.per_minute`; don't give `/bot/message`
  its own limit without updating the rate-limited-minute count (ADR 0016 second amendment).
  Also (from P1-05): the bot shows `display` verbatim, including the grouped `amount_usd`; its
  wording for `too_large` and `below_minor_unit` comes from the flow YAML.
  *DONE 2026-10-21 (PR #18, two rounds). Decisions in ADR 0021 (owner: a double-submit is read
  again only on the same step, otherwise it gets the current question, or `done: true` if the
  session closed; `not_in_table` re-asks the currency; `BAD_REQUEST` accepted; ratified: per-step
  retries, the first message is never an answer, the `below_minor_unit` wording, `bob` = BOB,
  `won` ambiguous) and the ADR 0001 second amendment (`symfony/yaml` 7.4). Round 2 added
  `Flow/SessionSweeper`, a lazily built upstream client, loader hardening and
  `RoundingRuleTest` (INV-01, PHP in `api/src/`).*
- **P1-07** `resources/widget/` at `GET /bot/widget`, `channel=static`, `textContent` only, CSP,
  `nosniff`, `frame-ancestors`.
  *Acceptance:* `tests/Acceptance/P1_07_WidgetTest.php` — response has the CSP and `nosniff`
  headers; widget JS contains no `innerHTML`, `toFixed` or `Intl.NumberFormat`; by hand, a
  `<script>` typed into the chat shows as text.
  Also: remove `X-Powered-By` from every response; make the `Authorization: Bearer` parsing strict
  (no trailing newline accepted).
  Also (from P1-11): the built-in widget at `/bot/widget` is same-origin, so a blocked user gets the
  403 envelope; show its `message_for_user` verbatim. A widget embedded from another origin gets
  the 403 without CORS headers, so `fetch` fails; show a generic "try again later" for any network
  failure.
  Also (from P1-06): open the session with `{"text": ""}` and `Content-Type: application/json`;
  render `reply` with `textContent` (line breaks via `white-space: pre-line`); skip an empty
  `reply`; show buttons only when present; lock input while a request is in flight; show the
  `BAD_REQUEST` and 429 texts verbatim (CORS runs before the rate limiter, so the 429 is readable);
  on `done: true`, end the conversation and offer a restart. The widget checks of INV-01 aren't
  built yet: the acceptance test should also reject `Math.round`.
  *DONE 2026-10-22 (PR #21, three rounds). Decisions in ADR 0022 (owner: favicon 204 plus an SVG
  icon, unthrottled widget routes until P3-01, the widget harness in the suite, lazy open,
  "Start chat"). Output safety is enforced by Trusted Types and the widget harness's DOM proxy;
  INV-01 in the widget by the harness's verbatim-text checks and a frozen scan; INV-04 is checked
  over the production route table. The lead's by-hand check (a typed `<script>` shows
  as text) passed in a browser.*
- **P1-08** `Metrics/ReportQuery`, `ReportFormatter`, `CsvWriter` → `GET /reports` (json/csv) and
  `bin/report.php` (table/csv/json); `GET /health` (public and admin views).
  *Acceptance:* `tests/Acceptance/P1_08_ReportsTest.php` — over seeded events, funnel counts per
  step and channel match; days split on `reports.timezone`; a currency named `=CMD()` is escaped in
  CSV; public `/health` shows only `status`.
  Also: decide what `/health` does when the database is behind or can't be opened (ADR 0019): treat
  the 500 as "unhealthy", or route `/health` before the database opens.
  Also (from the P1-04 review):
  - Count real upstream calls as `miss` plus `stale` events, minus those with `reason=no_key`. A
    `blocked` lookup makes no call, and a blocked lookup that served stale has `result=blocked`,
    `stale=true`. This counts `/latest/USD` calls only: give `/codes` and `/quota` calls their own
    events, or state in the report that they are excluded (P1-10 checks quota used).
  - Cache `/quota` for `health.quota_cache_minutes`. The quota block ends on the 1st (UTC), but
    upstream refreshes the quota on `refresh_day_of_month`; show both or follow upstream.
  - Include the backoff count in the admin `/health` breaker state.
  - The download-progress abort in `ExchangeRateClient` is unit-tested only (`MockHandler` never
    calls `progress`); say so in its docblock.
  Also (from P1-11): reports may count `ip.block` and `ip.unblock` events (by `block_source` and
  `reason`) and blocked requests (`requests.blocked`); neither ever shows an IP.
  Also (from P1-05): a `/convert` 4xx or 503 answered by the handler writes both an `api.request`
  and an `error` event, so don't count it twice; rate-limited and INTERNAL 500 responses have only
  the `error` event, and IP-blocked requests have neither. To recompute report amounts exactly,
  store the `rate_scaled` (and `rate_as_of`) used by each conversion first, e.g. in the
  `api.request` meta; nothing stores it today (ADR 0011 amendment).
  Also (from P1-06): sweep with `App::sessionSweeper()` before counting; in the funnel, separate
  sessions that never got an answer (page views abandoned at `amount`); `bot.step` can carry
  `UPSTREAM_UNSUPPORTED_CODE` (`not_in_table`); bot conversions write no `api.request` event and
  no `rate_scaled` (ADR 0021).
  Also (from P1-07): serve CSV through `TextResponse` (add it to the INV-04 route-table test);
  request-log reports should separate widget asset and `/favicon.ico` hits; sessions opened by a
  focus and never answered still count as abandoned at `amount` (ADR 0022).
  *DONE 2026-10-22 (PR #23, three rounds). Decisions in ADR 0023 and amendments to ADRs 0011,
  0012, 0016, 0019, 0021 and 0022; the owner accepted all seven as built, plus no rate limit on
  `/reports` and `/health` until P3-01 and the field name `passed`. `GET /health` routes before
  the database opens and answers 503 `UNHEALTHY`, which settles the ADR 0019 question above. The
  acceptance currency `=CMD()` now folds to `other` and never reaches a CSV cell; `CsvWriter`
  guards it directly.*
- **P1-09** `db/seed_demo.php`; `docs/README.md` (incl. a privacy note on the request log: raw
  IPs, 30-day retention, blocks; ADR 0016), `docs/INSTALL_LOCAL.md` (incl. CA bundle setup, and
  the ADR 0019 operations: run `php api/bin/migrate.php` after `composer install` and on every
  pull; a 500 with `SchemaOutOfDate` in the log means "run migrate"; one migrate at a time; back up
  `api/var/app.db` first; a daily `--prune` job; `seed_demo` runs after migrate and writes cents;
  what `migrate.php` prints and how to repair on `SchemaCorrupt`; that `ScaledRate::fromRate`
  needs `ini_set`, which must not be in `disable_functions`), `docs/REPORTS.md`. Add an acceptance
  test that the CLI exits 1 on a corrupt schema version.
  Also (from P1-11): the README privacy note covers `php api/bin/ip.php list` (manual blocks
  without an expiry keep an IP until removed), ended blocks kept one window after their end, and
  block events that never hold an IP. `INSTALL_LOCAL.md` says that behind a same-host proxy
  `TRUSTED_PROXY` must be set, or every client looks like loopback and is never blocked
  automatically, and that changing `ratelimit.per_minute` reinterprets the last window's counts.
  Also (from P1-07): `INSTALL_LOCAL.md` sets `expose_php=Off` and `display_errors=Off` and uses
  `-d display_errors=0` in the documented `php -S` command; says `ADMIN_TOKEN` must be visible
  ASCII and gives the exact `cors.allowed_origins` form (`https://host[:port]`, lowercase, no
  trailing slash); explains the iframe and `<script>` embeds (for the `<script>` embed, a host
  page's CSP must allow the API origin in `script-src`, `style-src` and `connect-src`; for the
  iframe, in `frame-src`); and says Node is optional locally (the widget harness skips without it,
  CI runs it).
  Also (from P1-08): `docs/REPORTS.md` covers the sections, the CSV columns, every report note
  (outside the flow, closed without an end event, `other`, unreadable amounts, the sweep bound,
  request-log retention, no stored rate) and how to read the funnel identity and `passed`
  (ADR 0023); `INSTALL_LOCAL.md` says monitors must use `GET /health` (`HEAD` is a counted 404 on
  a healthy database) and that `GET /health` is the one request that answers on an old schema
  (503 `UNHEALTHY`);
  `seed_demo` writes canonical labels and `rate_scaled`/`rate_as_of`, or demo data shows `other`
  and the "no stored rate" note.
  *Acceptance:* following `INSTALL_LOCAL.md` on a clean checkout reaches a working `/convert` with
  demo data, without Docker.
  *DONE 2026-10-22 (PR #26, three rounds; `docs/REPORTS.md` through lead PR #25 after a harness
  hook refused the builder's write). Decisions in ADR 0024 (owner: no stored rate table; the six
  seed decisions and the derived session ids accepted; the docs-only `variables_order=EGPCS` fix
  for the demo server). Owner process decisions, in the LOG: the builder may create the three
  docs, P1-09 only; `REPORTS.md` through PR #25; the scope of rounds 2 and 3. The walkthrough
  runs INSTALL's commands and compares its output blocks with real output; INSTALL's `/convert`
  step runs in-process with the mocked upstream.*
- **P1-10** End-to-end run: CLI and widget against the real key, then `/reports`. Log quota used in
  `docs/LOG.md`. **Deliver MVP.** The lead repeats the by-hand widget check (a typed `<script>`
  shows as text) on the release build, and tries the iframe and `<script>` embeds from a second
  origin listed in `cors.allowed_origins` (never tried by hand in P1-07). For quota used, read the
  report's `latest` endpoint row: `/quota` calls are counted apart, and whether upstream counts
  them is unverified at the time (settled in the ADR 0012 third amendment). Check it: read the
  admin `/health` view twice, more than `health.quota_cache_minutes` apart with no rate refresh in
  between, compare the quota left, and log the result. Run it on a new database without demo rows
  (ADR 0024): start the server with INSTALL section 7's real-use command and run the CLI bot, both
  in shells without `DB_DSN` set; before the run, check that no event has `"demo": true` in its
  meta and that `php api/bin/report.php` shows no sessions; log which database file was used. The
  demo's completed sessions on both channels would pass the acceptance below on their own.
  Also (from P1-09): on the release checkout, follow `INSTALL_LOCAL.md` by hand where the review
  couldn't: register and run the prune task (Windows, and cron where available), make a backup and
  restore it, run the `sqlite3` lines where `sqlite3` is installed, and check the `git clone` URL;
  log what didn't work as written.
  *Acceptance:* owner sees one completed session per channel in `/reports`, and quota used is ≤ 3
  calls.
  Also (from P1-04): compare `FallbackCurrencies` with the live `/latest/USD` codes (the list was
  written without network access), and look for Guzzle 8.3.x patch releases (ADR 0001 amendment).
  Also (from P1-06): look for `symfony/yaml` 7.4.x patch releases (ADR 0001 second amendment).
  Also (from P1-05): check `MinorUnits` against the live codes too (every quoted code should have
  an exponent; unknown codes fall back to 2), and close the cold-start allow-list limit (ADR 0012
  amendment, known limits).
  *DONE 2026-10-22: MVP delivered. The lead ran the real-key steps (owner-authorized for P1-10
  only) on a new `api/var/app.db`. The owner viewed `/reports` with the admin token: static 2
  started and 2 completed, cli 1 and 1, `latest` 1 call, `quota` 2 calls; quota used 3 calls,
  which upstream counts (ADR 0012 third amendment). The by-hand widget check, both embeds from a
  second origin, and the backup and restore passed; the owner registered, ran and removed the
  prune task. The live-code follow-ups were PR #28 (two rounds): `FallbackCurrencies` and
  `MinorUnits` cover the 166 live codes, and no dependency had a newer release. Decisions in the
  ADR 0012 and 0011 third amendments and the ADR 0001 note. The run used `e761e7e`; PR #28
  (`38ceb76`) changed only the two static code lists and their tests, checked by the gate with
  the mocked upstream.*

### Phase 2 — LLM normalizer (feature-flagged)

- **P2-01** `Normalize/`: `LlmClient` interface, extraction schema
  `{amount, currency, installments, confidence}`, confidence threshold, fallback to the
  deterministic parser. Extracted amounts go through `AmountParser` and the validators.
- **P2-02** Adapters: `OllamaAdapter` (default, `OLLAMA_URL`, `LLM_MODEL`), `AnthropicAdapter`,
  `OpenAiAdapter`; selected by `LLM_PROVIDER`. `llm.call` events: provider, model, tokens,
  latency, fallback flag.
- **P2-03** `POST /normalize`; `FlowEngine` uses it only when `llm.enabled=true`. Tests with mocked
  adapter, including prompt-injection inputs. The normalizer goes in `FlowEngine::read()`, ahead of
  the same readers (ADR 0021).
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
  Also (from P1-11): canonicalize addresses in `ClientIp` (`inet_ntop(inet_pton())`, deciding on
  `::ffff:a.b.c.d` from dual-stack sockets), so manual blocks and `rate_limits` keys match the
  proxy's `X-Forwarded-For` hop (`TRUSTED_PROXY` is compared parsed since L-04 cleanup A); exempt
  the Typebot host from automatic blocking (a config list) or retune `block.error_responses`, since
  all bot traffic comes from it and it doesn't forward users' IPs; with nginx in front,
  `TRUSTED_PROXY` must be nginx's container address, so check that the API doesn't see the Docker
  bridge gateway for every client; add a covering index on `requests` (migration 4) if tuning
  shows the `error_responses` scan matters.
  Also (from P1-07): in the image's `php.ini` set `expose_php=Off`; in nginx use
  `fastcgi_hide_header X-Powered-By` and `server_tokens off`, and don't add a second CSP with
  `add_header`; rate-limit or cache the widget's static files; answer `HEAD`,
  `/apple-touch-icon*.png` and `/robots.txt` without a counted 404 (ADR 0016 third amendment,
  ADR 0022).
  Also (from P1-08): rate-limit `/reports` and `/health` (in nginx or the app), or decide they
  stay open; a limiter must not break `/health` on a read-only database (ADR 0016 fourth
  amendment).
  Also (from the PHP2Shell review, ADR 0001 third amendment): pin the image to a patch release
  of 8.3.36 or later (for example `php:8.3.36-fpm-alpine`, or by digest), run PHP-FPM rather
  than mod_php, set the `php.ini` lines of `INSTALL_LOCAL.md` section 1, have nginx reject
  multipart request bodies, cap `client_max_body_size` and never pass `PHP_VALUE` or
  `PHP_ADMIN_VALUE`; decide whether those rejections answer 444 and are imported like the
  deny-list drops (INV-08, ADR 0016) or return the envelope (INV-04).
- **P3-02** Config API: `config` table, `GET /config`, `PUT /config` with per-key type and range
  checks, `config.changed` events.
- **P3-03** `POST /events`: per-channel ingest token, allow-listed `event_type`/`channel`/`step`,
  `meta` size cap, rate limit.
- **P3-04** `GET /capabilities` and generated `docs/CAPABILITIES.md`.
- **P3-05** Typebot flow with HTTP Request blocks against catalog endpoints; the server-issued
  `session_id` stored and sent back (not `{{resultId}}`); a way to mark sessions
  `channel=typebot`; money only from `display.*` (ADR 0004 amendment). Export to
  `typebot/flows/fx_installments.json` + sub-flow templates (validate-or-retry).
- **P3-06** Loader snippet reading `config.bot.frontend`; `/health` checks the Typebot viewer for
  automatic fallback to the static widget. The loader must load `widget.js` from the API's `/bot/`
  path (the widget derives its endpoint from its own `src`) and can fall back through
  `data-fx-target`; if the Typebot viewer frames anything, `frame-ancestors` needs its origin
  (ADR 0022).
- **P3-07** Contract test: every HTTP block in the export maps to a catalog endpoint/field. The
  `/bot/message` response fields (ADR 0021) are part of the contract.
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
  as corrupt once `app_state` exists. Make the breaker write Postgres-safe, and make
  `UpstreamBreaker::succeeded()` read and delete in one transaction, so it can't erase a trip
  another process wrote in between (ADR 0012 amendment). On Postgres a failed INSERT aborts the
  transaction, so the UPDATE fallback in `UpstreamBreaker::write()` needs a savepoint or an upsert
  (P1-05). The session sweep is one transaction; on Postgres a failed event INSERT aborts it, so it
  needs a savepoint per event, and its busy check reads SQLite codes 5/6 only, so on Postgres a
  lock error would fail the message instead of skipping the sweep (P1-06).
- **L-04** Tooling backlog from the P0-06 review. Triaged 2026-10-22 (LOG 2026-10-22). Cleanup A
  (correctness and safety) is done (PR #33, LOG); the owner scheduled cleanup B next, with a
  builder, tester and judge:
  - **Cleanup B, tests, comments and small code tidy-ups:**
    - owner decisions from cleanup A's review (2026-10-22): `require.php` becomes `~8.3.36`, 8.3
      only, and `SmokeTest` checks that constraint (ADR 0001 fourth amendment);
      `ClientIp::sameAddress` unmaps `::ffff:a.b.c.d` before comparing, as `isLoopback` does
      (ADR 0016 fifth amendment); `ConfigRepository` rejects a non-integer `amount.max` when the
      config loads;
    - cleanup A's review nits: a test for the no-create open flag in
      `Database::fromEnvironment`; a test that tells the sweep's close from a message's by
      `expires_at` alone; the orphaned `kernel()` docblock and the misnamed test in
      `KernelBlockingTest`; one prefix variable in `migrate.php`; the lines over 100 columns in
      `public/index.php`, `seed_demo.php` and `L_04_CleanupATest`; U+0085 in `CsvWriter`'s UTF-8
      branch; the docblocks and `usage:` lines that say `php bin/<script>.php` become
      `php api/bin/<script>.php`, with their pinned tests;
    - `RoundingRuleTest` adds `floor(`, `ceil(` and `fdiv(`, scans `api/bin`, `api/db` and
      `api/public`, and drops `ReportFormatter` from the allowed files;
    - `EntryPointEndToEndTest` moves onto `BuiltInServer`;
    - `P1_09_SeedAndDocsTest` anchors the REPORTS example parts and checks its quoted notes,
      counts the demo `DB_DSN` lines per section, normalises whitespace in the open-ids check,
      drops the duplicated DSN check, tells `VACUUM INTO` from a copy and calls `Bootstrap`;
    - the `P1_10_LiveCodesTest` sha256 assertion gets a failure message; `ScaledRateTest` and
      `InstallmentCalculatorTest` reseed `mt_srand` in `tearDown`;
      `InstallmentCalculator::exactTotal()` becomes private or narrower;
    - the report's "outside the flow" note reads "N session(s) ended at, or wait on, a step
      outside the flow's order; the funnel places each at the known step after its last answer."
      (already in `REPORTS.md`);
    - stale comments: `IpBlock`, `QuotaStatus`, `App.php`, `RequestLog`, `ConvertService` and
      `Conversion`, the `P1_10_LiveCodesTest` docblock ("owner decision"), and the `widget.js`
      lines over 100 columns.
  - **Owner-owned items** (CI, hooks, the Makefile, GUARDRAILS wording, `CLAUDE.md`) are in issue
    #31, and the GR-030 precedent in issue #30; they leave this list when the owner settles them.
  - Cleanup B replaces the matching parts of the items below, and the lead deletes those parts at
    its close-out. Everything else below stays, for the reason in LOG 2026-10-22.
  - Move `actions/cache` to v5 (v4 runs on the deprecated Node 20 runtime; CI warns).
  - Write hooks atomically in `install-hooks.php` (temp file + rename), so a failed write can't
    leave a truncated hook.
  - `make init FORCE=0` should not force (`$(if $(FORCE),…)` treats any non-empty value as true).
  - Decide how `git merge --squash` commits get a valid subject (git's default "Squashed commit of
    the following:" is rejected by `commit-msg`).
  - Optionally prune managed hook backups (those carrying the marker).
  - Optionally compare admin tokens through HMACs, so the comparison doesn't reveal token length.
  - The `ScaledRate` sweep calls `mt_srand` with a fixed seed; reseed in `tearDown` so later tests
    don't inherit the RNG state.
  - (P1-03) The `AmountParser` sweep checks values but not swaps of one accepted input for another;
    the pinned `SWEEP_*_READ` counts must change only with a rule change, explained in the commit.
  - (P1-03) `TextNormalizer` folds only precomposed Latin accents; decomposed forms (a letter plus
    U+0301) fall through to `unknown`. Normalize to NFC if intl becomes available.
  - (P1-11) `Http\IpBlocking` and `Db\Retention` depend on each other (`RATE_LIMIT_DAYS`,
    `assertWindowMinutes()`); move the block settings into a small holder (or Config).
  - (P1-11) The docblock of `Http\IpBlock` still says `--prune` deletes rows with
    `expires_at <= now`; it now waits one window after the end.
  - (P1-11) The unique-clash retry in `IpBlocks` can't happen on SQLite and is untested; cover it
    in L-03's `PostgresTest`, where concurrent inserts can clash.
  - (P1-05) `InstallmentCalculator::exactTotal()` is public and accepts exponents 0..12, though
    only 0..4 are used; make it private or narrow the range. `ConvertService` and `display()` both
    compute the exact total (harmless, well under a microsecond per call).
  - (P1-05) The `ConvertService` and `Conversion` docblocks call the raw numbers "the nearest
    double"; they are products of the nearest doubles of the amount and rate. Fix the wording.
  - (P1-06, P1-09) `RoundingRuleTest`: add `floor(`, `ceil(` and `fdiv(`, and scan `api/bin/`,
    `api/db/` and `api/public/` too (`bin/report.php` arrives in P1-08). It can't see dynamic calls
    (`array_map('round', …)`, `$f = 'round'; $f()`, `sprintf($fmt, …)`); the behaviour tests are
    the backstop.
  - (P1-06) The loader's `!!` error could name the line; `FlowDefinition::steps()` is used only by
    tests; `Step` is a bag of nullable fields; `abandon()` takes 9 parameters; `read()` returns a
    4-tuple although `InstallmentsReading` exists; a later `FlowLoader` / `FlowDefinition` split
    would shrink the loader.
  - (P1-06) "Just one payment" maps to `installments.default`; if the owner changes the default
    from 1, change the button text too.
  - (P1-07) Add an ETag (or Last-Modified) to the widget files so views revalidate instead of
    downloading again; log the `AllowedOrigins` warning once per bad configuration instead of per
    request (PHP keeps no state between requests, so remember, for example, a hash of the dropped
    list in `app_state`); re-wrap the `widget.js` lines over 100 columns (the header comment and
    line 243). Move `EntryPointEndToEndTest` onto `BuiltInServer`, so its server gets ADR 0018's
    gate flags and an empty `EXCHANGERATE_API_KEY`. Proposal for the owner (CI is owner-owned):
    pin Node with `actions/setup-node` so the widget harness doesn't depend on the runner image.
  - (P1-08) Derive `ReportQuery::STEPS` from `FlowDefinition`, keeping the list as a fallback when
    the YAML is broken; word the "outside the flow" note to cover open sessions too; split
    `ReportQuery` (about 900 lines) into per-section parts before the P3 rollups; add a
    `SessionSweeper::sweepAll()` instead of the batch size wired in `App::reportQuery()`; read
    `bin/report.php`'s timezone from `App`'s config once config is editable (P3); add an
    `AppState` helper for the three `app_state` upserts (`Migrator`, `UpstreamBreaker`,
    `QuotaCache`) before L-03. Drop `Metrics/ReportFormatter.php` from `RoundingRuleTest`'s
    allowed files, since it doesn't round (the owner may then narrow INV-01). Fix two stale code
    comments: `QuotaStatus` says `blocked` happens only during an auth block, and `App.php` says
    every request fails closed on an old schema.
  - (P1-09) The `RequestLog` docblock should list `rate_limits` among the IP tables. Tighten
    `P1_09_SeedAndDocsTest`: anchor the REPORTS example parts and check its quoted notes against
    `ReportQuery` [19], count the demo `DB_DSN` lines per section [E2b], normalise whitespace in
    the open-ids check [R2-6], drop the duplicated DSN check [R3-3], make the backup test tell
    `VACUUM INTO` from a plain copy [tester r2 D], and call `Bootstrap` itself in the dotenv test
    instead of phpdotenv directly [tester r2 G]. Indent the code blocks under INSTALL section 8's
    "The report:" bullet (the marker regex in `P1_09_SeedAndDocsTest` must then allow leading
    spaces) [round-1 nit]. Lead: accepted as a known limit (ADR 0024), not fixed: the seed's raw
    session INSERT and its template choice [16]. Owner's call: make `Env::get` prefer the process
    environment, so the demo server needs no `variables_order` option (ADR 0024).
  - (P1-10) At each release, recapture the live codes (update the fixture, its count, date and
    sha256) with the dependency check, since nothing detects a code upstream adds. The capture
    needs a real `/latest/USD` call, so the owner makes it or authorizes it each time; P1-10's
    authorization doesn't carry over. Give the sha256 assertion in `P1_10_LiveCodesTest` a
    failure message that says to recapture. Owner's call: whether to offer the withdrawn
    currencies upstream still quotes (ANG, BGN, HRK, SLL, ZWL); not offering them needs a deny
    list. Still untried by hand: the S4U form of the prune task, `schtasks --%`, cron, and the
    `sqlite3` lines (not installed on the dev host).

## 11. Working agreements for the agent

- Follow `CLAUDE.md` for all development rules; this plan defines *what* to build.
- Every agent brief carries "one plain shell command per tool call" (owner, 2026-10-21): no
  chains, loops, pipes into scripts or heredocs. Use Write/Edit for file changes and Grep/Glob for
  searches where possible.
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
