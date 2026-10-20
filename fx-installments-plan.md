# FX Installments — Build Plan for Claude Code

> Agent brief. Read fully before writing code. Decisions below are final unless the owner changes them.
> Design discussion and rationale: Claude.ai chat **"Payment conversion app architecture"** (Oct 20, 2026, America/Bogota). Find it by that title with past-chat search when available. It holds the reasoning behind every decision, the Landbot/Typebot/Botpress comparison, and the LLM discussion; the owner may also post run logs and feedback there. The repo docs (`docs/DECISIONS.md`, `docs/LOG.md`) are the primary source of truth; the chat is secondary context.

## 1. Goal

Small, measurable app with two components:

1. **REST API** — `GET /convert?amount=<usd>&cy=<ISO4217>&installments=<n?>` returns the converted amount in the target currency and the monthly installment amount, using [ExchangeRate-API](https://www.exchangerate-api.com) free tier (1,500 req/month, rates refresh once per day, API key sits in the URL path → server-side only).
2. **Chatbot** following a strict linear pipeline: greet → ask USD amount → ask target currency → (optional) installments → call the API → show a friendly message. The bot never computes rates itself.

Example: `GET /convert?amount=200&cy=BRL` → `{ "amount_usd": 200, "currency": "BRL", "converted": 1045.80, "rate": 5.229, "installments": 1, "installment_amount": 1045.80 }`

Everything must be **measurable**: ExchangeRate calls, currencies/countries requested, bot sessions (started / completed / abandoned, with the step), every error by code, with easy reports.

## 2. Decisions (final)

| Topic | Decision |
|---|---|
| Language | PHP 8.3, no framework. Composer: `guzzlehttp/guzzle`, `vlucas/phpdotenv`, `phpunit/phpunit`, `symfony/yaml`. |
| Database | SQLite via PDO for metrics, cache, config, sessions. Must be switchable to Postgres (see §8). |
| Chatbot MVP | Static flow defined in YAML, executed by a PHP state machine. **No LLM in the MVP.** Deterministic parsing only. |
| LLM | Phase 2, feature-flagged normalizer in front of the deterministic validators. Providers: Ollama (default), Anthropic, OpenAI. Never replaces validation. |
| Typebot | Phase 3, optional richer front-end, self-hosted, calling the same endpoints. The static flow stays as fallback. |
| Deployment | Primary: `docker compose` with the PHP app as a service inside the Typebot stack (profiles). Required alternate: **local install without Docker** — PHP 8.3 (`pdo_sqlite`, `curl`, `mbstring`), Composer, `php -S`. |
| Installments | Optional param, integer ≥1, cap from config (default 60), default from config (`DEFAULT_INSTALLMENTS=1`, monthly). |
| Rounding | `converted` and `installment_amount` to 2 decimals; `rate` unrounded. |
| Non-dev extensibility | Typebot flow (Phase 3) and `flows/default.yaml` are the editing surfaces; the PHP app exposes a documented **capability catalog** of small endpoints. |

## 3. Non-negotiable rules

- **Sanitization at every boundary.** `amount`: `FILTER_VALIDATE_FLOAT`, > 0, ≤ cap (config, default 1,000,000), max 2 decimals, reject NaN/INF/scientific notation. `cy`: trim, uppercase, `^[A-Z]{3}$`, then allow-list against cached ExchangeRate `/codes`. `installments`: int, 1..max. User input is never interpolated into an upstream URL unless it passed the allow-list.
- **Upstream hygiene.** Timeout 3–5 s, check `"result":"success"`, validate types before trusting `conversion_rate`/`conversion_result`. Map upstream errors (`unsupported-code`, `quota-reached`, `invalid-key`) to 400/503 with generic messages. Never leak the upstream body or the API key.
- **Cache** rates per currency until `time_next_update_unix` (SQLite table; APCu on top if available). Record cache hits so avoided calls are measurable.
- **Response envelope** on every endpoint: `{ "ok": true, "data": {...} }` or `{ "ok": false, "error": { "code": "...", "message_for_user": "..." } }`. Front-ends branch on `ok` and display `message_for_user` verbatim.
- **Every** meaningful action writes an event through the single `Metrics` class. No ad-hoc logging.
- Secrets only in `.env`. `JSON_THROW_ON_ERROR`, `Content-Type: application/json`, `htmlspecialchars` on anything rendered in the widget.
- Tests first for validators and installment math. PHPUnit suite must stay green.

## 4. Repository structure

```
fx-installments/
├── docker-compose.yml          # api; profiles: typebot (builder, viewer, postgres), ollama
├── .env.example
├── api/
│   ├── Dockerfile              # php:8.3-fpm-alpine + nginx, ext-pdo_sqlite, apcu
│   ├── composer.json
│   ├── public/index.php        # single entry point, tiny router
│   ├── src/
│   │   ├── Http/               # Router, Request, JsonResponse, AdminAuth middleware
│   │   ├── Validation/         # Validators — single source of truth
│   │   ├── Rates/              # ExchangeRateClient, RateCache, QuotaMonitor
│   │   ├── Convert/            # ConvertService, InstallmentCalculator, CurrencyCountryMap
│   │   ├── Metrics/            # Metrics (event writer), ErrorCodes, Rollup, ReportQuery
│   │   ├── Config/             # ConfigRepository, defaults
│   │   ├── Flow/               # FlowDefinition (YAML loader), FlowEngine, SessionStore
│   │   ├── Capabilities/       # CapabilityCatalog
│   │   └── Normalize/          # Phase 2: NormalizeService, LlmClient + adapters
│   ├── flows/default.yaml      # the static bot flow
│   ├── db/schema.sql, db/seed_demo.php
│   ├── bin/migrate.php, rollup.php, report.php, bot.php
│   ├── resources/widget/       # built-in chat page (vanilla JS, no build step)
│   ├── resources/dashboard/    # reports + settings + capabilities pages (Chart.js via CDN)
│   └── tests/
├── typebot/flows/*.json, typebot/templates/*.json     # Phase 3
└── docs/README.md, INSTALL_LOCAL.md, INSTALL_DOCKER.md, CAPABILITIES.md, REPORTS.md, ADDING_A_FEATURE.md
```

## 5. Data model (SQLite, PDO — keep SQL portable)

- `events(id, ts, source 'api'|'bot'|'upstream'|'llm', channel 'static'|'typebot'|'cli'|NULL, event_type, session_id, step, currency, country, amount_usd, installments, status 'ok'|'error', error_code, latency_ms, meta JSON)` — append-only source of truth.
- `daily_rollups(day, currency, country, upstream_calls, cache_hits, api_calls, api_errors, bot_sessions, bot_completed, bot_abandoned, errors_by_code JSON, llm_calls, llm_fallbacks)` — filled nightly by `bin/rollup.php`; reports read rollups and fall back to `events` for today.
- `rate_cache(currency, rate, fetched_at, next_update_unix)`
- `config(key, value, updated_at)` — runtime settings.
- `currency_country(code, country, source)` — seeded from `/codes` plus an overrides file.
- `sessions(session_id, channel, step, collected JSON, retries, created_at, updated_at, expires_at)`

Event types: `upstream.call`, `api.request`, `bot.session_start`, `bot.step` (valid/invalid), `bot.session_completed`, `bot.session_abandoned`, `config.changed`, `error`, and in Phase 2 `llm.call`.

Error codes (stable strings): `VALIDATION_AMOUNT`, `VALIDATION_CURRENCY`, `VALIDATION_INSTALLMENTS`, `UPSTREAM_TIMEOUT`, `UPSTREAM_QUOTA`, `UPSTREAM_UNSUPPORTED_CODE`, `UPSTREAM_AUTH`, `UPSTREAM_MALFORMED`, `BOT_MAX_RETRIES`, `BOT_SESSION_EXPIRED`, `LLM_UNAVAILABLE`, `LLM_LOW_CONFIDENCE`, `INTERNAL`.

## 6. Endpoints

| Endpoint | Phase | Purpose |
|---|---|---|
| `GET /convert?amount&cy&installments?` | 1 | Core conversion + installment amount |
| `POST /bot/message {session_id?, text}` → `{reply, buttons?, step, done}` | 1 | Static flow engine; the contract every channel uses |
| `GET /bot/widget` | 1 | Built-in chat page |
| `POST /events {session_id, channel, event_type, step?, status?, meta?}` | 1 | External channels push funnel events |
| `GET /reports?from&to&format=json\|csv` (admin token) | 1 | Summary, currencies/countries, funnel by step and channel, errors by code, upstream usage, (Ph2) LLM usage |
| `GET /reports/dashboard` (admin token) | 1 | Static HTML + Chart.js |
| `GET /health` | 1 | Upstream quota left, cache age, DB writable, (Ph2) LLM reachable, (Ph3) Typebot viewer up |
| `GET /config`, `PUT /config` (admin) | 1 | Runtime settings; every change logged |
| `GET /capabilities` | 1 | Catalog for non-devs; also rendered to `docs/CAPABILITIES.md` |
| `POST /normalize {text, expecting: amount\|currency\|installments\|any}` | 2 | LLM extraction → validated fields or `ok:false` |

Config keys (defaults): `installments.default=1`, `installments.max=60`, `amount.max=1000000`, `bot.max_retries=3`, `bot.session_ttl_minutes=30`, `bot.frontend=static`, `llm.enabled=false`, `llm.confidence_threshold=0.8`, `message.result_template`.

## 7. User interfaces for the MVP

1. **CLI** — `php bin/bot.php` (interactive; also pipeable for scripted tests).
2. **Built-in web widget** — `GET /bot/widget`, vanilla JS, buttons for shortcuts (top currencies, "Just one payment"), embeddable with one `<script>` or an iframe.
3. **Raw endpoint** — `POST /bot/message`, the contract for any future channel (Telegram, Typebot, mobile).

Plus `/convert` via curl, and reports via `/reports`, `bin/report.php`, dashboard (operator-facing).

## 8. Switching metrics from SQLite to Postgres

Design for it from day one:

- All DB access through PDO behind a `Database` factory reading `DB_DSN` (`sqlite:/path/app.db` or `pgsql:host=…;dbname=…`), `DB_USER`, `DB_PASS`.
- Keep SQL portable: no SQLite-only functions in hot paths; put dialect differences (JSON extraction, `INSERT … ON CONFLICT`, date truncation) in a small `Dialect` class with `SqliteDialect` / `PostgresDialect`.
- Ship `db/schema.sql` (SQLite) and `db/schema.pgsql.sql` (Postgres) generated from the same migration list in `bin/migrate.php`.
- In the Docker stack the Typebot Postgres is already running; switching is `DB_DSN=pgsql:host=postgres;dbname=fx_metrics` plus `bin/migrate.php`. Use a separate database, not Typebot's.
- Add a `tests/Integration/PostgresTest.php` that runs the same suite against Postgres when `TEST_PG_DSN` is set (skipped otherwise).

## 9. Static flow (MVP)

`flows/default.yaml` is data, not code:

```yaml
name: fx_installments
max_retries: ${config.bot.max_retries}
steps:
  - id: greet
    say: "Hi! I can convert a USD payment into your local currency, in monthly installments."
  - id: amount
    ask: "What is the installment amount in USD?"
    expecting: amount
  - id: currency
    ask: "Which currency or country should I convert to?"
    expecting: currency
    buttons: [BRL, COP, MXN, EUR]
  - id: installments
    ask: "How many monthly installments? (default 1)"
    expecting: installments
    optional: true
    buttons: ["Just one payment"]
  - id: result
    call: convert
    say: "{{amount_usd}} USD is about {{converted}} {{currency}} today (rate {{rate}}). In {{installments}} monthly installment(s): {{installment_amount}} {{currency}} each. Rates update once a day."
```

Deterministic parsing in the MVP: amount via validators; currency via regex + allow-list + a static synonym/country table (`Brazil`, `reais`, `R$` → `BRL`; Spanish and English names). Invalid input → friendly retry; after `max_retries` → `bot.session_abandoned` with the step. All events carry `channel`.

## 10. Phases and todo list

### Phase 1 — Deterministic MVP (PHP + Composer + SQLite only)

1. Scaffold: `composer.json`, `.env.example`, `public/index.php` router, envelope, admin-token middleware, `Database` factory + `Dialect`.
2. `db/schema.sql` + `bin/migrate.php` (tables in §5).
3. `Validation/Validators` with unit tests (amount, currency, installments, synonym table).
4. `Rates/`: ExchangeRate client (`/pair/USD/{cy}/{amount}`, `/codes`, `/quota`), `RateCache`, timeout/error mapping, `upstream.call` events with hit/miss.
5. `Convert/`: `ConvertService`, `InstallmentCalculator` → `GET /convert`. Tests with mocked upstream.
6. `Metrics/`: event writer, error codes, `POST /events`, `Rollup` + `bin/rollup.php`.
7. `ReportQuery` → `GET /reports` (json/csv) + `bin/report.php` (table/csv/json) + dashboard page.
8. `Config/`: `GET /config`, `PUT /config`, `config.changed` events.
9. `Flow/`: YAML loader, `FlowEngine`, `SessionStore` with TTL, `POST /bot/message`, `bin/bot.php`.
10. `resources/widget/` chat page at `GET /bot/widget`; `channel=static`.
11. `GET /health`, `GET /capabilities`, generated `docs/CAPABILITIES.md`.
12. `db/seed_demo.php`; `docs/README.md`, `INSTALL_LOCAL.md` (no Docker), `REPORTS.md`.
13. End-to-end run: CLI + widget against a real key, verify `/reports`. **Deliver MVP.**

### Phase 2 — LLM normalizer (feature-flagged)

14. `Normalize/`: `LlmClient` interface, JSON extraction schema `{amount, currency, installments, confidence}`, confidence threshold, fallback to deterministic parser. Deterministic validators always run after extraction.
15. Adapters: `OllamaAdapter` (default, `OLLAMA_URL`, `LLM_MODEL`), `AnthropicAdapter`, `OpenAiAdapter`; selected by `LLM_PROVIDER`. `llm.call` events: provider, model, tokens, latency, fallback flag.
16. `POST /normalize`; `FlowEngine` uses it only when `llm.enabled=true`. Tests with mocked adapter, including prompt-injection inputs.
17. Reports: LLM section (usage, est. cost, fallback rate, completion rate with vs without).
18. `/health` checks LLM reachability.

### Phase 3 — Typebot front-end (Docker profile)

19. `docker-compose.yml`: `api` + `--profile typebot` (builder, viewer, postgres) + `--profile ollama`; `docs/INSTALL_DOCKER.md`.
20. Build the flow in Typebot with HTTP Request blocks against catalog endpoints; `{{resultId}}` as `session_id`; `channel=typebot`. Export to `typebot/flows/fx_installments.json` + reusable sub-flow templates (validate-or-retry).
21. Loader snippet reading `config.bot.frontend` to mount Typebot embed or the static widget; `/health` checks the viewer for automatic fallback.
22. Contract test: every HTTP block in the export maps to a catalog endpoint/field.
23. `docs/ADDING_A_FEATURE.md` (no-code track vs new-capability track).

### Later / stretch

24. `bin/export-typebot.php` — starter Typebot JSON from the YAML.
25. Telegram / WhatsApp webhook channel for the static flow.
26. Metrics on Postgres (§8) in the Docker stack.

## 11. Working agreements for the agent

- Work phase by phase, in the listed order; do not start Phase 2 code before Phase 1 is delivered and green.
- Commit per numbered item with a message that references it (`feat(p1-05): ConvertService + /convert`).
- Keep `docs/CAPABILITIES.md` generated, never hand-edited.
- When a decision is unclear, check the chat referenced at the top before asking; if still unclear, ask the owner.
- Report progress and anomalies (quota usage, upstream errors, test failures) in `docs/LOG.md`, dated; the owner mirrors anything relevant into the "Payment conversion app architecture" chat.
- Record each new or changed decision as a short ADR entry in `docs/DECISIONS.md` (context, decision, consequences), starting from the table in §2.
