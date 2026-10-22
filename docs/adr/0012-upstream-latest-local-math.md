# 0012 — Upstream: one `/latest/USD` call per refresh, local math, stale fallback

- **Status:** Accepted (amended 2026-10-21: breaker kinds and backoff, refresh floor, stale age; see
  "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The draft used `/pair/USD/{cy}/{amount}`, which costs one call per currency per day: ~160
currencies would need ~4,800 calls a month against a 1,500 quota. A cache miss also used upstream's
`conversion_result` while a hit recomputed locally, giving two code paths that could disagree. The
draft had no plan for upstream being down, and Guzzle exceptions include the URL, which contains
the key.

## Decision

- Call `/latest/USD` only when `now ≥ next_update_unix`; one call refreshes every rate (~30 calls a
  month). Its codes are the currency allow-list; `/codes` is used only for names. The stored
  `next_update_unix` has a floor, and the breaker must be closed (amendment items 4 and 6).
- Always compute `converted = amount × rate` locally.
- On refresh failure, serve a cached table younger than `rates.max_stale_hours` with `stale: true`.
  Every response carries `rate_as_of`; the age is measured from it (amendment item 5). A static
  `FallbackCurrencies` list validates codes on a cold start.
- `UpstreamBreaker` stops calls after `quota-reached` (until next month, UTC) and after
  `invalid-key` (until `php api/bin/upstream.php reset`). The amendment below adds
  `inactive-account` to the reset case and a growing backoff after every other failed refresh.
- The client catches every exception and maps it to an error code; exception text never leaves
  it. Tests inject a mocked handler; the test bootstrap forbids real network calls and uses a fake
  key.

## Consequences

- Quota use depends on time, not traffic, and can't be burned by a script.
- One code path for conversion.
- Users may see a result based on a rate up to `rates.max_stale_hours` old, clearly marked.

## Amendment (2026-10-21): every failed refresh trips the breaker

**Context.** Building P1-04 (PR #12, three rounds) showed that stopping only after quota and key
errors leaves quota use bounded by traffic. During a timeout or a malformed answer, every lookup
would call upstream again, with up to 4 s of waiting each. The round-1 builder added a flat
5-minute backoff for other failures. The round-1 tester found that an `unsupported-code` answer
from `/latest/USD` bypassed it. The round-1 judge found that a failed `rate_cache` write did too,
and that the flat backoff still costs 288 calls a day, so a persistent fault would use the whole
monthly quota in about 5 days. The co-leads' round-2 brief then required every failed refresh to
trip the breaker. The owner made two decisions on 2026-10-21. First, the backoff grows from
5 minutes to 1 hour and resets on a successful refresh. Second, it starts at 1 minute: 1, 5, 10,
20, 40, 60. Before P1-05 the owner made a third (item 9). The rest (strength order, refresh
floor, stale age, state validation) is the builder's design, which the tester and judge accepted
in round 2.

**Decision.**
1. **Three kinds of trip**, stored as one `app_state` row (`upstream_breaker`, JSON,
   `BreakerState`):
   - `quota`: after `quota-reached`. It blocks until 00:00 UTC on the 1st of the next month.
   - `auth`: after `invalid-key` or `inactive-account`. It blocks until
     `php api/bin/upstream.php reset`.
   - `backoff`: after any other failed refresh. That covers timeouts, connect and network errors,
     non-2xx answers, bad JSON or shapes, oversized bodies, any other `error-type`,
     `unsupported-code` on `/latest/USD`, and a fetched table that couldn't be stored in
     `rate_cache` (`reason=store_failed`; the fetched rate is still served for that request).
   - A missing or malformed key (`no_key`) trips nothing, because no call was made.
2. **Backoff schedule (owner decisions).** After the 1st, 2nd, … consecutive failed refresh,
   calls stop for **1, 5, 10, 20, 40, then 60 minutes**, and 60 minutes for every failure after
   that (`UpstreamBreaker::BACKOFF_SCHEDULE`). `BreakerState::failures` counts the consecutive
   failures. A successful refresh clears the backoff, so the next failure waits 1 minute again.
3. **Strength order: auth > quota > backoff.** An open trip of equal or greater strength is never
   replaced. So a slow request that fails late can't shorten an auth or quota block, and one outage
   isn't counted twice: a failure while a backoff is still open doesn't move the schedule on; only a
   failure after the backoff has ended raises `failures`. An expired trip is replaced. A success
   clears a backoff (open or not) or an expired trip, but leaves an open quota or auth block alone.
4. **Refresh floor.** The stored `next_update_unix` is
   `max(upstream's time_next_update_unix, fetched_at + 300)` (`RateCache::MIN_REFRESH_SECONDS`).
   This stops a late upstream, whose next-update time has already passed, from causing a call on
   every lookup.
5. **Stale age** is measured from `last_update_unix` (the `rate_as_of` users see), not
   `fetched_at`. A table is usable while its age is strictly less than `rates.max_stale_hours`.
6. **While blocked**, a usable stale table is served with `stale: true`. The `upstream.call` event
   then has `result=blocked`, `stale=true`. Without one, the lookup returns `UPSTREAM_QUOTA`,
   `UPSTREAM_AUTH`, or, for a backoff, the code of the failed refresh that opened the current
   backoff.
7. **Reset is recorded.** `php api/bin/upstream.php reset` writes an `upstream.breaker_reset` event
   (`cleared`, `was_open`, `by=cli`; never the key).
8. **Stored state is validated.** A row whose kind and code don't match, a backoff longer than
   1 hour or with no failures, a quota block ending after the next 1st, or an auth trip with an
   end time counts as no trip.
9. **503 during an `unsupported-code` backoff (owner decision, 2026-10-21).** While a backoff
   opened by `unsupported-code` on `/latest/USD` is open, a lookup without a usable stale table
   answers 503, not 400. The fault is upstream's, not the user's (lead's rationale); a 400 there
   would also count toward `block.error_responses` (ADR 0016) and could block users for an
   upstream fault. The failed refresh that opens the backoff answers after the backoff is recorded,
   so it gets the 503 too (lead's reading), unless the breaker write itself failed and no backoff
   exists. A currency missing from a usable table (`not_in_table`,
   raised by `RateCache`) stays a 400 `UPSTREAM_UNSUPPORTED_CODE`. P1-05 applies this; its
   acceptance test covers it.
   **Implemented in P1-05 (PR #16):** `RateCache` marks the `UpstreamError` when such a backoff is
   in force, on the blocked path and on the refresh that opens it. `ErrorCodes::httpStatus($code,
   $duringUnsupportedCodeBackoff)` and `messageForUser()` give 503 and "Exchange rates are
   temporarily unavailable. Please try again later." for that case only; the flag is refused for
   every other code. A failed breaker write, or a race that leaves another kind of trip in force,
   stays 400 (the lead's reading). No new error code.

**Consequences.**
- Quota use stays bounded by time. A persistent fault that is neither quota nor auth costs at most
  28 calls in its first day (calls at minutes 0, 1, 6, 16, 36, 76, then hourly) and 24 a day
  after that. That is about 720 calls if it lasts 30 days, within the 1,500 quota. In normal use a
  refresh costs about 30 calls a month.
- On a cold start (no stored table) a failed refresh, or a failed `rate_cache` write after a good
  one, gives users errors for the backoff window: 1 minute at first, longer if failures repeat.
- **Known limits:**
  - If writing the breaker row itself fails (`meta.breaker_write_failed`), no backoff is recorded,
    and lookups can call upstream again until the database accepts writes. Until P1-05, a failed
    INSERT went unnoticed and `trip()` returned a backoff that was never stored; P1-05 fixed it
    (`UpstreamBreaker::write()` now rethrows when its fallback UPDATE changes no row). On Postgres
    a failed INSERT aborts the transaction, so that fallback needs rework (L-03).
  - `succeeded()` reads and then deletes outside a transaction. A trip that another process writes
    in between can be erased (L-03, with the Postgres-safe breaker write).
  - Processes don't lock each other. Two lookups that miss at the same moment can both refresh:
    one extra call, same data.
  - The quota block ends on the 1st (UTC). Upstream actually refreshes the quota on
    `refresh_day_of_month` from `/quota` (P1-08).
