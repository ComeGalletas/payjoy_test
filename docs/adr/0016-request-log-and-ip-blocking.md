# 0016 — Request log and IP blocking

- **Status:** Accepted (amended 2026-10-21: retention boundaries and block rows, see "Amendment")
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
  `ip_blocks` with `expires_at <= now`. It keeps `events`, open sessions and manual blocks without
  an expiry. The 1-day and 30-day windows are constants; `requests.retention_days` is config.
- **When a block is active.** A block is active while `now < expires_at` (or forever when
  `expires_at` is NULL, manual only). P1-11's block check and `--prune` use the same contract.
- **One row per IP.** `ip_blocks.ip` is the primary key, so P1-11 writes blocks as select-then-write
  in one transaction (ADR 0010): re-blocking an IP replaces its expired row; a manual block
  replaces an automatic one; an automatic block never replaces an active manual block. Block
  history lives in `requests.blocked`.
- `--prune` refuses (exit 1) a database whose schema is behind the code.
