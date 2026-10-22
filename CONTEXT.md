# Context — FX Installments glossary

Use these exact terms in code, tests, docs, events and commit messages. If a new concept needs a
name, add it here in the same commit that introduces it.

## Money

| Term | Meaning | Not to be confused with |
|---|---|---|
| **amount** / `amount_usd` | The **total** price the customer paid, in USD. Max 2 decimals, > 0, ≤ `amount.max`. On `/convert` only the strict form `^\d{1,7}(\.\d{1,2})?$` is accepted. Stored as `amount_usd_cents`. | A per-installment amount. The API never takes one. |
| **canonical amount** | The strict string form of an amount (`1000.50`). `AmountParser` produces it from bot input. | What the user typed (`1.000,50`). |
| **currency** / `cy` | Target ISO 4217 code (`BRL`, `COP`…), uppercase, on the allow-list. | A country. Countries map to a currency via the currency-country map. |
| **minor unit** | The smallest unit of a currency per ISO 4217 (`MinorUnits`): JPY 0 decimals, BRL 2, KWD 3, CLF 4. All splitting is done in integer minor units. | Always "cents". |
| **rate** | Upstream USD → currency rate from `/latest/USD`. Never rounded except in `display.rate`. | |
| **rate_as_of** | When upstream last updated the rate (`time_last_update_unix`). | When we fetched it (`fetched_at`). |
| **stale** | A result served from a cached rate after a failed refresh, within `rates.max_stale_hours`. Marked `stale: true`. | A cache hit, which is fresh. |
| **converted** | `amount_usd × rate`, computed locally. Full precision. | `display.converted`; upstream `conversion_result`, which is not used. |
| **installments** / `n` | Number of monthly payments, integer 1..`installments.max`, default `installments.default`, at least one minor unit each. | |
| **installment amount** / `installment_amount` | `converted / n`. Full precision. | `display.installment`. |
| **display block** / `display` | English-formatted strings `/convert` returns for presentation: `amount_usd`, `rate`, `converted`, `installment`, `last_installment`, `regular_count`, `even`, `rate_as_of`. Produced only by `InstallmentCalculator::display()`, exactly from the amount's cents and the stored `rate_scaled`; money strings are grouped, `amount_usd` included (ADR 0011 amendment). | Any rounding a front-end does itself — forbidden. The raw float numbers, whose last digits can differ. |
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
| **upstream call** | One HTTP request to upstream. Every rate lookup writes an `upstream.call` event with result `hit`, `miss`, `stale` or `blocked`; only `miss` and `stale` lookups called upstream, except those with `reason=no_key`. |
| **refresh** | One `/latest/USD` call that rewrites every row of `rate_cache`; happens only when `now ≥ next_update_unix` and the breaker is closed. |
| **stored table** | The rows the last successful refresh wrote to `rate_cache` (`RateTable`): rates, `rate_as_of`, `next_update_unix`, `fetched_at`. |
| **dropped currency** | A rate that a refresh skipped because it failed a check; listed in the `miss` event's `meta.dropped`, by code only when the key was a valid code. The rest of the table is still stored. |
| **breaker** | `UpstreamBreaker`: stops upstream calls after any failed refresh except `no_key`. Its trip is `quota` (until the 1st of next month, UTC), `auth` (until `php api/bin/upstream.php reset`) or `backoff` (ADR 0012 amendment). |
| **unsupported-code backoff** | A backoff opened by `unsupported-code` on `/latest/USD`. While it is open, a lookup without a usable stale table answers 503 instead of 400 (ADR 0012 amendment item 9). |
| **backoff** | The breaker's trip after any failed refresh other than quota or auth: 1, 5, 10, 20, 40, then 60 minutes per consecutive failure, cleared by a successful refresh. |
| **quota** | The free-tier monthly request budget (1,500), read from upstream `/quota` for the admin health view. |
| **allow-list** | Currency codes in the latest stored rate table. Input not on it never reaches upstream. |
| **fallback currency list** | Static list (`FallbackCurrencies`) of ISO 4217 currency codes plus upstream's non-ISO territory codes (FOK, GGP, IMP, JEP, KID, TVD); validates codes when the cache is empty. |
| **synonym table** | Static map from currency names and symbols, in English and Spanish (`reais`, `R$`, `euro`), to a currency code (`CurrencySynonyms`). Country names live in the currency-country map, except countries with more than one currency (`Panama`), which are on the ambiguous list. |
| **ambiguous currency text** | Text that several currencies share (`$`, `peso`, `dollar`, `libra`, `pound`, `won`, `¥`); always unclear, never guessed. |
| **code word** | An English word that is also a currency code (`CurrencySynonyms::CODE_WORDS`: `all cup kid mad mop sos top try`; `bob` left it when Bolivia was mapped, so it is the BOB code in any case). Typed in capitals it is the code (`TRY`); in any other case it is ambiguous (`try`, `Try`). |
| **currency-country map** | PHP array (`CurrencyCountryMap`) linking country names to currencies. A country is recorded only when the user typed one. |
| **admin token** | `ADMIN_TOKEN` from `.env`, sent only as `Authorization: Bearer`; unset means all admin requests are refused. |
| **client IP** | The address a request is counted under: `REMOTE_ADDR`, or the last `X-Forwarded-For` hop when `TRUSTED_PROXY` equals `REMOTE_ADDR`. |
| **trusted proxy** | `TRUSTED_PROXY`: the one peer whose `X-Forwarded-For` header is believed. |
| **rate-limit window** | A fixed one-minute UTC window (`window_start`) in which an IP's requests are counted against `ratelimit.per_minute`. |
| **preflight** | A CORS `OPTIONS` request; answered with a 200 envelope, with `Access-Control-*` headers only for an allowed origin. |
| **IP block** | A row in `ip_blocks`: requests from that IP get 403 `IP_BLOCKED` until `expires_at` or until unblocked. An **automatic block** comes from a `block.*` threshold; a **manual block** from `php api/bin/ip.php block`. |
| **unblock** | `php api/bin/ip.php unblock`: ends a block now (`expires_at = now`) and keeps the row one window, so the requests before it never count again. |
| **clean slate** | After a block ends or is lifted, only requests from its end on count toward the next block (ADR 0016 second amendment). |
| **rate-limited minute** | A rate-limit window in which an IP went over `ratelimit.per_minute` (at least one 429). `block.rate_limit_hits` counts these, not 429 responses. |
| **error response (for blocking)** | A non-blocked 4xx other than 429; what `block.error_responses` counts. 5xx never count. |
| **block exemption** | An address never blocked automatically: loopback, the trusted proxy itself, and anything that isn't a valid IP. It can still be blocked by hand. |
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
| **retry** | A re-ask after invalid input. Counted per step: after `bot.max_retries` failures at one step the session is abandoned (ADR 0021). |
| **conversion retry** | A retry caused by the conversion, not the typed answer: `too_large` re-asks the amount, `below_minor_unit` the installments, `not_in_table` the currency. |
| **double-submit** | A message that loses the save race on its session (a double click, a client retry). It is read again only if the session still waits on the same step; otherwise it gets the current question, or `done: true` if the session closed (ADR 0021). |
| **say step** | A flow step that only shows text (`greet`), at the start of a session. |
| **result template** | `say_single`, `say_even` or `say_uneven`, chosen from `installments` and `display.even`. |
| **max-retries reply** | `max_retries_reply` in the flow file: the text shown when a session is abandoned by max retries. |
| **session sweeper** | `Flow/SessionSweeper`: closes expired open sessions as `BOT_SESSION_EXPIRED`, in one transaction, without needing the flow file. |
| **clarification** | A retry asking the user to retype an amount `AmountParser` couldn't read unambiguously, or a currency answer that was ambiguous. |
| **ok / unclear / invalid** | The three outcomes of reading bot input (`Validation\Outcome`): read; couldn't be read unambiguously (ask again); rejected — out of range, not allowed, unknown or malformed. |
| **validation reason** | `meta.reason` on a validation failure. Amount: `format`, `not_positive`, `over_max`, `unclear`, `too_large` (the total reaches 2^53 minor units). Currency: `format`, `not_allowed`, `unknown`, `ambiguous`, and `not_in_table` (code `UPSTREAM_UNSUPPORTED_CODE`, from the conversion). Installments: `format`, `out_of_range`, `below_minor_unit` (fewer minor units than installments). |
| **folding** | `TextNormalizer`: case-insensitive comparison of user text that also removes precomposed Latin accents (`México` = `mexico`), independent of locale. |
| **completed session** | Reached the `result` step and showed a result. |
| **abandoned session** | Ended by max retries (`BOT_MAX_RETRIES`), expiry (`BOT_SESSION_EXPIRED`) or an upstream error; the event records the step. |
| **channel** | Where a session runs: `static` (every `/bot/message` session, including the built-in widget, until P3-05 adds `typebot`), `cli`, `typebot`. |
| **front-end** | Anything that shows the bot to a person: widget, CLI, Typebot. |
| **normalizer** | Phase 2 LLM step that extracts fields from free text before the deterministic parser and validators. Never replaces them. |

## Metrics

| Term | Meaning |
|---|---|
| **event** | One append-only row in `events`, written only through `Metrics`. |
| **api.request event** | The event `/convert` writes for every request its handler answers (rate-limited, blocked and INTERNAL 500 requests have none): the validated fields, status, error code, latency; meta `endpoint`, `http_status`, `stale` or `reason`, `minor_units_default`. Never upstream text, keys or IPs. |
| **error event** | The `error` event the Kernel writes for every error response except blocked requests (ADR 0016): meta `http_status`, `method`, path without query (percent-encoded, as in the request log), exception class. |
| **request log** | The `requests` table: one row per incoming request with the raw client IP, kept `requests.retention_days` (ADR 0016). |
| **migration** / **migration list** | One numbered schema change in the append-only list in `Db/Migrations`; run with `bin/migrate.php` (ADR 0020). |
| **schema version** | `app_state.schema_version`, the last migration applied; the app refuses a database behind the code (ADR 0019). |
| **prune** / **retention** | `bin/migrate.php --prune` deleting expired rows per the ADR 0016 amendment; `events` are kept. |
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
