# 0016 — Request log and IP blocking

- **Status:** Accepted
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
- **Error events.** The P1-01 policy stays: every error response is also an `error` event; the error
  log gets the exception class, file and line only, never the message.
- **Automatic blocking.** An IP that reaches `block.rate_limit_hits` 429s or
  `block.error_responses` error responses within `block.window_minutes` is blocked for
  `block.duration_minutes`. Blocks live in `ip_blocks` with an expiry; manual blocks may have none.
  Operators use `php bin/ip.php block|unblock|list`.
- **Blocked requests.** The Kernel checks `ip_blocks` before routing. A blocked request is still
  recorded (`blocked = 1`), gets a minimal 403 `IP_BLOCKED` envelope and `Connection: close`, and
  runs no handler.
- **Dropping the connection.** PHP under `php -S` or php-fpm can't drop a TCP connection before
  answering. In Phase 3 (P3-01) nginx reads a deny list exported from `ip_blocks` and returns
  nginx's 444, which closes the connection without a response.

## Consequences

- Every request costs one write; under a flood the log grows until the block applies. SQLite in WAL
  mode absorbs a few thousand writes a second, which is enough for the MVP.
- Raw IPs are personal data (Colombian Ley 1581): they live only in `requests` and `ip_blocks`,
  `requests` is pruned after 30 days, and expired blocks are pruned too.
- New tables, an error code, five config keys, a bin script and a task (P1-11), scheduled before
  P1-05 so `/convert` is protected from its first release.
- Block thresholds are first guesses and need tuning against real traffic.
