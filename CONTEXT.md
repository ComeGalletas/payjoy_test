# Context — FX Installments glossary

Use these exact terms in code, tests, docs, events and commit messages. If a new concept needs a
name, add it here in the same commit that introduces it.

## Money

| Term | Meaning | Not to be confused with |
|---|---|---|
| **amount** / `amount_usd` | The **total** price the customer paid, in USD. Max 2 decimals, > 0, ≤ `amount.max`. On `/convert` only the strict form `^\d{1,7}(\.\d{1,2})?$` is accepted. | A per-installment amount. The API never takes one. |
| **canonical amount** | The strict string form of an amount (`1000.50`). `AmountParser` produces it from bot input. | What the user typed (`1.000,50`). |
| **currency** / `cy` | Target ISO 4217 code (`BRL`, `COP`…), uppercase, on the allow-list. | A country. Countries map to a currency via the currency-country map. |
| **minor unit** | The smallest unit of a currency per ISO 4217 (`MinorUnits`): JPY 0 decimals, BRL 2, KWD 3, CLF 4. All splitting is done in integer minor units. | Always "cents". |
| **rate** | Upstream USD → currency rate from `/latest/USD`. Never rounded except in `display.rate`. | |
| **rate_as_of** | When upstream last updated the rate (`time_last_update_unix`). | When we fetched it (`fetched_at`). |
| **stale** | A result served from a cached rate after a failed refresh, within `rates.max_stale_hours`. Marked `stale: true`. | A cache hit, which is fresh. |
| **converted** | `amount_usd × rate`, computed locally. Full precision. | `display.converted`; upstream `conversion_result`, which is not used. |
| **installments** / `n` | Number of monthly payments, integer 1..`installments.max`, default `installments.default`, at least one minor unit each. | |
| **installment amount** / `installment_amount` | `converted / n`. Full precision. | `display.installment`. |
| **display block** / `display` | English-formatted strings `/convert` returns for presentation: `amount_usd`, `rate`, `converted`, `installment`, `last_installment`, `regular_count`, `even`, `rate_as_of`. Produced only by `InstallmentCalculator::display()`. | Any rounding a front-end does itself — forbidden. |
| **regular payment** | Each of the first `display.regular_count` installments, all equal to `display.installment`. | |
| **final payment** / **last installment** | `display.last_installment`: the last payment, which absorbs the remainder. | |
| **remainder** | Minor units left after flooring `total_m / n`; always added to the final payment. | |
| **even split** / **uneven split** | `display.even` true when every installment is equal; the bot picks `say_even` or `say_uneven`. | |

## API and upstream

| Term | Meaning |
|---|---|
| **envelope** | Every JSON response: `{ok: true, data}` or `{ok: false, error: {code, message_for_user}}`. |
| **message_for_user** | Generic, safe text front-ends show verbatim. Never contains upstream bodies or secrets. |
| **error code** | A stable string from `Metrics/ErrorCodes` (`VALIDATION_AMOUNT`, `UPSTREAM_QUOTA`…). |
| **upstream** | ExchangeRate-API. Only `Rates/ExchangeRateClient` talks to it. |
| **upstream call** | One HTTP request to upstream; recorded as `upstream.call` with result `hit`, `miss`, `stale` or `blocked`. |
| **refresh** | One `/latest/USD` call that rewrites every row of `rate_cache`; happens only when `now ≥ next_update_unix`. |
| **breaker** | `UpstreamBreaker`: stops upstream calls after `quota-reached` (until next month) or `invalid-key` (until reset). |
| **quota** | The free-tier monthly request budget (1,500), read from upstream `/quota` for the admin health view. |
| **allow-list** | Currency codes in the latest stored rate table. Input not on it never reaches upstream. |
| **fallback currency list** | Static ISO code list (`FallbackCurrencies`) used to validate codes when the cache is empty. |
| **synonym table** | Static map from names, country names and symbols (`Brazil`, `reais`, `R$`) to a currency code. |
| **currency-country map** | PHP array (`CurrencyCountryMap`) linking country names to currencies. A country is recorded only when the user typed one. |
| **admin token** | `ADMIN_TOKEN` from `.env`, sent only as `Authorization: Bearer`; unset means all admin requests are refused. |
| **client IP** | The address a request is counted under: `REMOTE_ADDR`, or the last `X-Forwarded-For` hop when `TRUSTED_PROXY` equals `REMOTE_ADDR`. |
| **trusted proxy** | `TRUSTED_PROXY`: the one peer whose `X-Forwarded-For` header is believed. |
| **rate-limit window** | A fixed one-minute UTC window (`window_start`) in which an IP's requests are counted against `ratelimit.per_minute`. |
| **preflight** | A CORS `OPTIONS` request; answered with a 200 envelope, with `Access-Control-*` headers only for an allowed origin. |
| **IP block** | A row in `ip_blocks`: requests from that IP get 403 `IP_BLOCKED` until `expires_at`. Automatic (`block.*` thresholds) or manual (`bin/ip.php`). |
| **cents** / `amount_usd_cents` | A USD amount stored as an exact integer number of cents (ADR 0017). |
| **scaled rate** / `rate_scaled` | A rate stored as `round(rate × 10^10)` (ADR 0017). |
| **config key** | A setting such as `installments.max`; read-only defaults in Phases 1–2, editable via `PUT /config` in Phase 3. |
| **capability** / **capability catalog** | Phase 3: documented endpoints listed by `GET /capabilities` and `docs/CAPABILITIES.md`. |

## Chatbot

| Term | Meaning |
|---|---|
| **flow** | The ordered list of steps in `flows/default.yaml`. Data, not code, and the only source of bot wording. |
| **step** | One flow entry: `greet`, `amount`, `currency`, `installments`, `result`. |
| **flow engine** | `Flow/FlowEngine`, the state machine that runs a flow. |
| **session** | One conversation, with a server-issued `session_id`, stored in `sessions` with a TTL and a status (`open`, `completed`, `abandoned`). |
| **retry** | A re-ask after invalid input. After `bot.max_retries` the session is abandoned. |
| **clarification** | A retry asking the user to retype an amount `AmountParser` couldn't read unambiguously. |
| **completed session** | Reached the `result` step and showed a result. |
| **abandoned session** | Ended by max retries (`BOT_MAX_RETRIES`), expiry (`BOT_SESSION_EXPIRED`) or an upstream error; the event records the step. |
| **channel** | Where a session runs: `static` (built-in widget), `cli`, `typebot`. |
| **front-end** | Anything that shows the bot to a person: widget, CLI, Typebot. |
| **normalizer** | Phase 2 LLM step that extracts fields from free text before the deterministic parser and validators. Never replaces them. |

## Metrics

| Term | Meaning |
|---|---|
| **event** | One append-only row in `events`, written only through `Metrics`. |
| **error event** | The `error` event the Kernel writes for every error response except blocked requests (ADR 0016): meta `http_status`, `method`, path without query, exception class. |
| **request log** | The `requests` table: one row per incoming request with the raw client IP, kept `requests.retention_days` (ADR 0016). |
| **funnel** | Sessions per step and channel: started → each step → completed / abandoned. |
| **report** | Output of `ReportQuery`, via `/reports` or `bin/report.php`; days split on `reports.timezone`. |
| **rollup** | Phase 3: one `daily_rollups` row per day, built by `bin/rollup.php`. |

## Work

| Term | Meaning |
|---|---|
| **owner** | Decides; edits `CLAUDE.md`, `AGENTS.md`, guards and policy files. |
| **lead** / **worker** | Agent roles defined in `CLAUDE.md`. |
| **work ID** | `P<phase>-<nn>` or `L-<nn>` from `docs/PLAN.md` §10; prefixes every commit subject. |
| **acceptance test** | The test file named in a task's `acceptance=`, run with `make acceptance TEST=<path>`. |
| **ADR** | Architecture decision record in `docs/adr/NNNN-<slug>.md`. |
