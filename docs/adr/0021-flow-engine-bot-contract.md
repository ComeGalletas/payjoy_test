# 0021 — Flow engine and bot message contract

- **Status:** Accepted (note 2026-10-22: the widget opens sessions lazily, ADR 0022)
- **Date:** 2026-10-21
- **Deciders:** owner
- **Relates to:** 0003 (static flow), 0004 (Typebot), 0011, 0012, 0013, 0016, 0018

## Context

P1-06 (PR #18, two rounds) built the static flow engine, `POST /bot/message` and `bin/bot.php`.
PLAN §9 and ADR 0003 fix the pipeline and its wording source, but left the request and response
contract, session rules, retry counting, concurrency and the flow file's schema open. The builder
chose these and listed them; the tester and judge reviewed them. The owner then decided the
double-submit rule and that a currency missing from the rate table re-asks the currency, accepted
the new `BAD_REQUEST` code as built, and ratified per-step retries, "the first message is never an
answer", `symfony/yaml` 7.4 (ADR 0001 second amendment), the `below_minor_unit` wording and the
`bob`/`won` changes. The round-1 judge's should-fixes and the tester's nits led the co-leads'
round-2 brief to require a sweep independent of the flow file and run in one transaction
(item 7), a CLI test under ADR 0018's gate flags (item 12), loader hardening (item 9) and a static
rounding test (item 5). How each is built, and everything else below, is the builder's design,
which the tester and judge accepted.

## Decision

1. **Request.** `POST /bot/message` takes a JSON object of at most 4 KiB, sent with
   `Content-Type: application/json` (parameters such as `charset` are ignored), with a string
   `text` and an optional string or null `session_id`. Anything else answers 400 `BAD_REQUEST`
   ("Sorry, that message could not be read. Please try again."). Requiring JSON means a page on
   another origin must pass a CORS preflight. **Owner-accepted as built:** `BAD_REQUEST` is a new
   stable error code and counts toward `block.error_responses` like any other 4xx (ADR 0016).
2. **Response.** `{session_id, reply, buttons?, step, done}`. Every flow outcome, abandonment
   included, is a 200 envelope; for a `POST`, only `BAD_REQUEST`, the rate limiter, IP blocks and
   `INTERNAL` answer otherwise (other methods get the router's 404 or the CORS preflight answer).
   - `buttons` is present only while the session is open and the waiting step has buttons.
   - `done` is true once the session is completed or abandoned. `step` is the waiting step,
     `result` when completed, or the step where the session was abandoned.
   - Reply parts are joined with `\n` (greeting plus first question; result plus stale note).
   - `reply` is empty only with `done: true`, for a race-losing message on a closed session
     (item 6).
   - CORS runs before the rate limiter on `/bot/message`, so a 429 still carries CORS headers.
   - HTTP sessions are `static` and the body can't choose a channel; `bin/bot.php` uses `cli`.
3. **Sessions.** Ids are `bin2hex(random_bytes(16))`, issued by the server. An unknown, malformed,
   expired or finished id starts a new session (except a message that lost the race, item 6), and so
   does a row whose `collected` is damaged or whose step the flow no longer asks.
   **Owner-ratified:** the message that starts a session is never read as an answer, so a `3` meant
   for an expired session can't become a 3 USD amount. `collected` holds only validated values
   (`amount_usd_cents`, `currency`, `country` only when typed, `installments`). The TTL
   (`bot.session_ttl_minutes`) slides with each message; a closed session gets `expires_at = now`.
4. **Retries (owner-ratified):** counted per step. The count resets when a new question is asked,
   and the session is abandoned with `BOT_MAX_RETRIES` when one step's count reaches
   `bot.max_retries`. A failed conversion that sends the user back to the step just answered keeps
   that step's count, so repeated failed conversions are bounded too.
5. **Conversion in the flow.** The result step uses the same path as `/convert`
   (`ConvertService`), and its templates use only `display.*`, the currency code and the
   installment count; `Flow/` does no money arithmetic (INV-01; `RoundingRuleTest` catches
   rounding and float formatting in `api/src/`).
   - `below_minor_unit` re-asks installments with `reasons.below_minor_unit`, which may include
     `{{message_for_user}}` (owner-ratified wording, settling PLAN §9 against the P1-05 note).
   - `too_large` re-asks the amount, keeping the currency and installments.
   - **Owner decision:** `not_in_table` (`UPSTREAM_UNSUPPORTED_CODE` for a currency missing from
     a usable table) re-asks the currency with `reasons.not_in_table` and counts as a currency
     retry, keeping amount and installments. This amends PLAN §9's "upstream errors end the
     session" for that case only.
   - Every other upstream error, including the 503 during an `unsupported-code` backoff, ends the
     session with the API's `message_for_user` (`upstream_error`) and `bot.session_abandoned`
     carrying the code.
6. **Double-submit (owner decision; the empty `reply` is the builder's choice).**
   `SessionStore::save()` is a compare-and-set on the open
   row. A message that loses the race re-reads the session: if it is still waiting on the same
   step, the text is read again; if it moved to another step, the reply is that step's question
   and buttons, with no event and no retry spent; if the winner closed the session, the reply is
   `done: true` with the closed session's id and an empty `reply`, and no new session starts.
   Only the winner's events are written.
7. **Expiry sweep.** `Flow/SessionSweeper` closes open sessions past `expires_at` as
   `bot.session_abandoned` with `BOT_SESSION_EXPIRED` and their last step. It needs only the
   session store and `Metrics`, not the flow file, so reports can sweep even if the YAML is
   broken (`App::sessionSweeper()`). One sweep (up to 500 sessions) is one transaction and rolls
   back on any exception; a failed event write is swallowed by `Metrics`, so the session still
   closes. If another connection holds the write lock, the sweep is skipped after the
   busy timeout and the message is still answered.
8. **Events.** `bot.session_start` (step `greet`, meta `flow`); `bot.step` (ok, or the
   `VALIDATION_*` or `UPSTREAM_UNSUPPORTED_CODE` code with `reason`, `retry` and `via: convert`
   when a conversion re-asked the step); `bot.session_completed` (meta `template`, `stale`);
   `bot.session_abandoned`. Columns come from `collected`. No event, `sessions` row or error-log
   line holds the typed text, an IP or a key. The IP is stored only by the HTTP layer
   (`requests`, `rate_limits`, `ip_blocks`; ADR 0016), never by the flow.
9. **Flow file.** Top-level keys are exactly `name`, `max_retries` (1–20), `max_retries_reply` and
   `steps`; steps may carry `reasons` (per field; the conversion reasons may use
   `{{message_for_user}}`), and the result step requires `upstream_error`.
   `${config.*}` and `{{config.*}}` resolve at load time from an allow-list of int keys; each text
   slot has its own placeholder allow-list, and rendering is a single pass, so user text never
   reaches a placeholder. The loader uses `symfony/yaml` with only
   `PARSE_EXCEPTION_ON_INVALID_TYPE`, refuses `!!` tags with a text check before parsing (this
   also refuses ` !!` in quoted text or a comment), requires every string to be UTF-8, checks the
   top-level keys first and caps the tree at 2000 nodes. The file is parsed for each request.
10. **Currency text.** `bob` is the BOB code in any case (Bolivia is mapped) and `won` is
    ambiguous (owner-ratified). `£` → GBP while `pound` stays ambiguous; `yuan` → CNY while `¥`
    stays ambiguous; 13 countries were added; Cuba isn't mapped, so `cup` stays a code word. No
    Spanish or Portuguese word needs the capitals rule.
11. **`bin/bot.php`** runs the engine in-process on the configured database: exit 0 when the
    session completed, 1 when it was abandoned, the input ended or something failed (stderr names
    the exception class only), 2 for usage errors.
12. **Lazy upstream client.** `RateCache` takes the client or a closure, and `App::rates()` builds
    `ExchangeRateClient` only on a refresh, so the CLI subprocess test runs with ADR 0018's gate
    flags.

## Consequences

- One contract for every channel; the bot can't diverge from `/convert`, because it shows the
  same `display` strings.
- A double-submit costs nothing when the step moved, and a finished session isn't replaced.
- **Known limits:**
  - A duplicate *invalid* message on the same step spends two retries; a race-losing different
    answer is dropped silently; a race loser may add one `upstream.call` event.
  - A message in flight when expiry closes its session gets `done: true` with an empty reply
    (L-04).
  - `below_minor_unit` at 1 installment can't be fixed by re-asking installments, so it ends by
    max retries; only an amount of about a cent reaches it.
  - The first message of a session is dropped, so a user who opens with `200` retypes it, and
    sessions that never answer count as abandoned at `amount` (P1-08). Since P1-07 the widget
    opens a session only on the first focus or "Start chat" (ADR 0022), so a page view alone opens
    none; a focus still does.
  - Bot conversions write no `api.request` event and no `rate_scaled` (P1-08).
  - The busy wait before a skipped sweep can add up to the busy timeout to a message; on Postgres
    the sweep needs a savepoint per event (L-03).
  - Typebot can't use `{{resultId}}` as the session id, and the body can't set `channel=typebot`
    (ADR 0004 amendment, P3-05).
  - Renaming a step in the YAML restarts every session in progress (their step is no longer asked).
  - A Windows console needs `chcp 65001` for accented CLI input.
