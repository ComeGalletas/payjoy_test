# 0016 — Request log and IP blocking

- **Status:** Accepted (amended 2026-10-21: retention boundaries and block rows, see "Amendment";
  amended again 2026-10-21: what counts toward a block, events, see "Second amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

P1-01 records every error response as an `error` event (meta: `http_status`, `method`, path
without the query string, exception class; no client IP). The review found that nothing bounds
these writes: unknown paths aren't rate-limited, a 429 still writes a row, and `events` is never
pruned, so an anonymous client can grow the database at line rate. Capping or sampling error
events was considered and rejected by the owner: every incoming request must be recorded, and
abusive IPs must be cut off instead.

## Decision

- **Request log.** Every request writes one row to a separate `requests` table: `ts`, raw client
  IP (resolved with the P1-01 trusted-proxy rule), `method`, `path` (truncated to 200 characters,
  query string never stored), `status`, `latency_ms`, `blocked`. Business events stay in `events`.
- **Retention.** `requests` rows older than `requests.retention_days` (default 30) are deleted by
  `bin/migrate.php --prune`.
- **Error events.** The P1-01 policy stays for requests that reach routing: every error response
  is also an `error` event. Blocked requests are the exception: they write only their `requests`
  row (`blocked = 1`), no `error` event, so blocking doesn't double the writes. The error
  log gets the exception class, file and line only, never the message.
- **Automatic blocking** (owner-confirmed; the thresholds are tuned later). An IP that reaches
  `block.rate_limit_hits` 429s or
  `block.error_responses` error responses within `block.window_minutes` is blocked for
  `block.duration_minutes`. Blocks live in `ip_blocks` with an expiry; manual blocks may have none.
  Operators use `php bin/ip.php block|unblock|list`.
- **Blocked requests.** The Kernel checks `ip_blocks` before routing. A blocked request is still
  recorded (`blocked = 1`), gets a minimal 403 `IP_BLOCKED` envelope and `Connection: close`, and
  runs no handler.
- **Dropping the connection.** PHP under `php -S` or php-fpm can't drop a TCP connection before
  answering. In Phase 3 (P3-01) nginx reads a deny list exported from `ip_blocks` and returns
  nginx's 444, which closes the connection without a response. Those requests never reach PHP, so
  nginx's access log for them is imported into `requests` (`blocked = 1`), keeping every request
  recorded.

## Consequences

- Every request costs one `requests` write, blocked ones included. Blocking stops handler work and
  `error` events for that IP, but not the request-log writes: until P3-01 a flood from a blocked IP
  still writes one row per request, at line rate. Only the 30-day retention bounds the table. SQLite
  in WAL mode absorbs a few thousand writes a second, which is enough for the MVP; from P3-01 nginx
  takes the flood and the import writes in batches.
- Raw IPs are personal data (Colombian Ley 1581): they live only in `requests` and `ip_blocks`,
  `requests` is pruned after 30 days, and expired blocks are pruned too. Manual blocks without an
  expiry keep that IP until an operator removes it; `bin/ip.php list` shows them so they can be
  reviewed. A privacy note describing the request log goes into `docs/README.md` (P1-09).
- New tables, an error code, five config keys, a bin script and a task (P1-11), scheduled before
  P1-05 so `/convert` is protected from its first release.
- Block thresholds are first guesses and need tuning against real traffic.

## Amendment (2026-10-21): retention boundaries and block rows

Decided while building P1-02.

- **Boundaries.** "Older than" is strict. `--prune` deletes `rate_limits` with
  `window_start < now − 1 day`, closed (`completed`/`abandoned`) sessions with
  `updated_at < now − 30 days`, `requests` with `ts < now − requests.retention_days`, and
  `ip_blocks` with `expires_at <= now` (changed by the second amendment: one window later). It
  keeps `events`, open sessions and manual blocks without an expiry. The 1-day and 30-day windows
  are constants; `requests.retention_days` is config.
- **When a block is active.** A block is active while `now < expires_at` (or forever when
  `expires_at` is NULL, manual only). P1-11's block check and `--prune` use the same contract.
- **One row per IP.** `ip_blocks.ip` is the primary key, so P1-11 writes blocks as select-then-write
  in one transaction (ADR 0010): re-blocking an IP replaces its expired row; a manual block
  replaces an automatic one; an automatic block never replaces an active manual block; a new
  automatic block on an active automatic one extends its `expires_at` to the later of the two
  (owner-confirmed). Block history lives in `requests.blocked`.
- `--prune` refuses (exit 1) a database whose schema is behind the code.

## Second amendment (2026-10-21): what counts toward a block, block events, stored paths

**Context.** Building P1-11 (PR #14, three rounds) needed rules the ADR left open. The round-1
builder made 12 decisions and listed them. The round-1 tester found that a path cut by bytes could
end in half a UTF-8 character, and that `unblock` followed by `--prune` lost the clean slate. The
round-1 judge found that counting every 429 let one burst block an IP, and everyone behind a shared
carrier NAT, for an hour. The owner then decided items 1, 4, 7 and 8 below and ratified items 5
and 6 as built. The co-leads added that 429s don't count toward `block.error_responses` (item 1).
The round-2 judge found that counting rate-limited minutes over `requests` made a flood's cost grow
with the flood; round 3 counts them from `rate_limits` (item 1) and the builder added the rules in
items 2 and 3 that this needs. Everything not attributed above is the builder's design, which the
tester and judge accepted.

**Decision.**
1. **What counts toward a block** (owner decision for the 429 rule):
   - `block.rate_limit_hits` counts **rate-limited minutes**: fixed one-minute UTC windows of
     `rate_limits` (the same `window_start` as `RateLimiter`) whose `count` is over
     `ratelimit.per_minute`, so the minute had at least one 429. The window is the current minute
     plus the `block.window_minutes − 1` whole minutes before it. A burst inside one minute never
     blocks.
   - `block.error_responses` counts **error responses**: non-blocked 4xx responses other than 429.
     5xx never count, since they are our failure or upstream's, not the client's. 429s don't count
     here (co-lead decision), so a burst can't block through this threshold either.
   - Each response checks only the threshold it can move: a 429 checks `rate_limit_hits`, any other
     4xx checks `error_responses`. The block's `reason` is the threshold that was reached.
   - Blocked requests never count toward a block and never extend one.
   - A threshold is reached at `>=`. In `requests`, a row exactly `block.window_minutes` old has
     left the window.
2. **Clean slate.** Requests before the IP's previous block ended never count again: `requests`
   rows count from `ts >= expires_at`, and rate-limited minutes from the minute after the end (from
   the end's own minute when the end is that minute's first second).
3. **Settings.** `block.window_minutes` is 1–1440, because `rate_limits` rows are pruned after a
   day. `block.duration_minutes` is 1–525600. Both counts are at least 1. A change to
   `ratelimit.per_minute` reinterprets the stored `rate_limits` counts at once, for at most one
   window.
4. **Block rows**, in addition to the first amendment:
   - A manual block replaces an active manual block (the latest operator command wins).
   - `php api/bin/ip.php unblock` ends a block now (`expires_at = now`) and keeps the row, so the
     clean slate applies.
   - **Prune boundary (owner decision):** `--prune` deletes an `ip_blocks` row only when
     `expires_at <= now − block.window_minutes × 60`, so the clean slate survives a prune. This
     replaces the first amendment's `expires_at <= now`. Manual blocks without an expiry are kept.
5. **Exemptions (ratified):** loopback addresses, the `TRUSTED_PROXY` address itself, and anything
   that isn't a valid IP are never blocked automatically. They can still be blocked by hand.
6. **Fail open (ratified):** if the block check can't read `ip_blocks`, the request is let through
   and the error log gets the exception class. A failed request-log, event or automatic-block write
   never fails the request. Blocking is abuse mitigation, not authorization.
7. **The 403 (owner decision):** `IP_BLOCKED` says "Too many unusual requests came from your
   network. Please try again later.", with `Connection: close` and no `Retry-After`, so the
   duration isn't revealed.
8. **Block events (owner decision),** with `events.source = api`, never the IP and never the
   operator's reason text:
   - `ip.block` when an automatic block starts or is extended: meta `block_source = auto`,
     `reason` (`rate_limit_hits` or `error_responses`), `duration_minutes` (the configured
     duration, which is also the time to the new expiry), `extended`.
   - `ip.block` from `bin/ip.php block`: `block_source = manual`, `reason` (`given` or `default`),
     `duration_minutes` or null, `by = cli`.
   - `ip.unblock` on every `bin/ip.php unblock`: `block_source` of the row or null, `was_active`,
     `by = cli`.
   - A block that changes nothing writes no event.
9. **Order.** The block check runs first, before CORS preflight, middleware and the rate limiter.
   The `requests` row is written after the response is built, and the automatic-block check runs
   after that, so the current request counts. `latency_ms` is the time from the start of the
   Kernel until the response is built, the same for blocked requests.
10. **Stored path and method,** in `requests` and in `error` events: query string and fragment
    dropped; every byte outside 0x21–0x7E percent-encoded (uppercase hex; escapes the client sent
    are kept as sent); then cut to 200 (path) or 16 (method) characters without splitting an escape
    or one UTF-8 character's escapes. The stored values are pure ASCII, so "200 characters" above
    holds.
11. **`bin/ip.php`:** `block <ip> [--minutes=N] [--reason=TEXT]`, `unblock <ip>`, `list`. It stores
    IPs in canonical form, refuses a database whose schema is behind the code or a missing database
    file (exit 1), and exits 2 on bad arguments. Its stdout shows IPs and reasons, since it is
    operator output; events, the error log and stderr never do.

**Consequences.**
- **Cost per request:** the blocking step adds one `ip_blocks` read and one `requests` insert to
  every request. A 429 adds one primary-key range read on `rate_limits` (at most
  `block.window_minutes` rows), whatever the IP's traffic. Another 4xx adds a scan of the IP's `requests` rows in the window. At
  most `block.error_responses` such scans happen per window, so the cost is linear in the IP's
  traffic, not quadratic. The round-3 tester measured about 2 ms per 429 at 10, 5,000 and 50,000
  rows, and about 4 ms per 404 at 20,000 rows. A covering index would need migration 4 and is
  deferred to P3-01.
- **Floods:** a client is blocked once it is rate-limited in 5 distinct minutes out of 10. Until
  then a flood writes `requests` rows and `error` events at line rate: about five minutes of them
  per block cycle, and `events` is never pruned. This partly reopens the Context's concern; it is
  accepted with the owner's 429 decision.
- **Shared addresses:** one busy minute behind a carrier NAT no longer blocks everyone behind it.
- **Known limits:**
  - One global `ratelimit.per_minute` is assumed. A route with its own limit would make the
    rate-limited-minute count wrong (P1-05, P1-06).
  - The Kernel and `RateLimiter` read the clock separately. At a minute boundary a 429 can land in
    the next minute, which delays a block by at most one request (L-04).
  - Requests that fail before the Kernel (a database that can't be opened, or a schema behind the
    code) write no `requests` row.
  - `ClientIp` doesn't canonicalize addresses, so a manual block typed in another form than the
    proxy's `X-Forwarded-For` hop doesn't match it (P3-01). An IPv6 client can rotate addresses
    inside its /64.
  - Escapes the client sent aren't normalized: `%c3%a9` and `%C3%A9` log as different paths.
  - A blocked cross-origin request gets no CORS headers, so a browser sees a network error, not
    the 403 text (P1-07).
  - The thresholds are still first guesses, to be tuned against real traffic.
