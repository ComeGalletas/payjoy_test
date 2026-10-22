# 0024 — Demo seed data

- **Status:** Accepted
- **Date:** 2026-10-22
- **Deciders:** owner
- **Relates to:** 0011, 0016, 0019, 0021, 0023

## Context

P1-09 (PR #26, three rounds) built `api/db/seed_demo.php`, so a throwaway database shows full
reports at once, and the local install guide. PLAN P1-09 asked that the seed run after migrate
and write cents, canonical labels and each conversion's `rate_scaled` and `rate_as_of`. Before
the build the owner decided that the seed writes no stored rate table (2026-10-22). The builder
chose everything else and listed six decisions; the owner accepted all six as built after round
1, and kept the demo session ids the builder derives (2026-10-22). The round-1 judge found that
the guide seeded the database real traffic would use, and the owner put that in round 2. The
round-2 judge found that a shell `DB_DSN` doesn't beat `.env`'s under PHP's built-in server (the
lead confirmed it with a probe); the owner chose the docs-only fix in round 3. How each is built
is the builder's design, which the tester and judge accepted. The owner's other P1-09 decisions
(the builder may create the three docs, P1-09 only; `REPORTS.md` through lead PR #25; the scope
of rounds 2 and 3) are process decisions, recorded in `docs/LOG.md`.

## Decision

1. **No stored rate table (owner decision; the rest of the list is the builder's).** The seed
   writes only `events`, `sessions` and `requests`: nothing in `rate_cache`, no `app_state` row
   (it only reads `schema_version`), nothing in `rate_limits` and, per item 8, nothing in
   `ip_blocks`. The first real conversion still makes the one real `/latest/USD` call.
2. **Content.** 14 bot sessions on the `static` and `cli` channels, spread over the default report
   period (fixed local times on the six days before the run, plus two sessions still open,
   started 10 and 3 minutes before it): completed, abandoned at several steps, never answered and
   open; seven `/convert` requests, two of them refused; a few error responses; one IP
   rate-limited, blocked and unblocked; and the matching request-log rows. Labels are canonical
   (the flow's step names, `ErrorCodes`, `CurrencyCountryMap` names, three-letter codes), amounts
   are integer cents, and each successful conversion carries `rate_scaled` and `rate_as_of` in
   its meta (ADR 0011 second amendment), so a report on demo data shows no `other` and no "no
   stored rate" note (ADR 0023).
3. **Refuse a database with data (owner-accepted).** It exits 1 when `events` or `sessions` holds
   a row (request-log rows alone, such as `/health` hits, are allowed). There is no `--force`:
   `events` are append-only and never pruned, and no tool deletes rows, so demo rows mixed into
   real ones would stay in every report.
4. **Deterministic (owner-accepted).** The same rows every time; only the dates move with the day
   you seed. Session ids are the first 32 hex digits of sha256 of
   `fx-installments demo session N` (owner decision: kept, although ADR 0021 item 3 issues
   random ids). Anyone who knows the scheme can drive the two open demo sessions until they
   expire, and each message extends them; that is harmless, since anyone can open a session.
5. **No options; exit codes 0, 1, 2 (owner-accepted).** 0 done; 1 refused or failed (no database
   file, which it never creates; a schema behind the code or corrupt; data present; a failed
   write), with only the exception class or a fixed text on stderr; 2 usage. `--help` and `-h`
   exit 0. Everything is one transaction: a failed write leaves no row.
6. **Demo lookups are cache hits (owner-accepted).** Its rate lookups are `upstream.call` events
   with result `hit`, so the report's `latest` row counts only real calls, which P1-10 reads.
   `php api/bin/upstream.php status` still says the cache is empty, because `rate_cache` has no
   rows.
7. **The demo marker (owner-accepted).** Every event the seed writes has `"demo": true` in its
   meta; reports ignore it. The expiry events a later sweep writes for the two open sessions come
   from the app and don't carry it (find them by their fixed ids); `sessions` rows carry no
   marker, and demo `requests` rows use the documentation ranges 192.0.2.0/24, 198.51.100.0/24
   and 203.0.113.0/24 (RFC 5737). No event holds an IP.
8. **Blocks as events only (owner-accepted).** The demo block and unblock are `ip.block` and
   `ip.unblock` events with no `ip_blocks` row, since the demo block ended long ago and the row
   would have been pruned; so `php api/bin/ip.php list` shows no demo IP.
9. **A throwaway database (round-1 judge; in the owner's round 2).** The guide seeds a separate
   file through a `DB_DSN` set in the shell (`sqlite:api/var/demo.db`, the builder's design), never
   the database real traffic uses, and says to start from a database without demo rows before
   real use and before P1-10. Round 3 added a test, by owner decision, that every demo command in
   the guide targets that file.
10. **The demo server needs `variables_order=EGPCS` (owner decision, round 3).** The CLIs see a
    shell `DB_DSN` over `.env`'s, because PHP copies the environment into `$_SERVER` and
    phpdotenv's immutable writer leaves a key it finds there or in `$_ENV`. PHP's built-in server
    with the usual `variables_order=GPCS` has the variable only in `getenv()`, so phpdotenv
    writes `.env`'s value and `Env::get`, which reads `$_ENV` and `$_SERVER` first, returns it.
    With `E`, PHP fills `$_ENV` from the environment, so phpdotenv keeps the shell value. The
    guide starts the demo server with `-d variables_order=EGPCS`, and a `BuiltInServer` test pins
    both cases. `Env` is unchanged.

## Consequences

- A throwaway demo database shows full reports at once, without a key, and the demo numbers in
  `docs/REPORTS.md` and `docs/INSTALL_LOCAL.md` are checked against a real run by
  `P1_09_SeedAndDocsTest`.
- ADR 0016's Consequences listed raw IPs in `requests` and `ip_blocks` only; `rate_limits` holds
  them too (ADR 0021 item 8). P1-09's README says so, and ADR 0016 is corrected as a note.
- **Known limits:**
  - Demo data seeded into the real database can't be removed; the guide's way back is a new
    database.
  - The numbers in the docs hold over the dates the seed prints (or on the day you seed).
  - The demo `ip.block` event records `duration_minutes` 60 whatever `block.duration_minutes`
    says, and each of its five rate-limited minutes has a single 429 and no `rate_limits` row,
    not the 31 or more requests that would cause it. A very long
    `bot.session_ttl_minutes` can place a demo row in the future, which aborts the seed, and in
    a time zone with daylight saving the fixed local times shift by an hour on a change day
    (L-04).
  - Under `php -S`, a shell `DB_DSN` loses to a `DB_DSN` line in `.env` (the template's empty one
    included) unless `E` is in `variables_order` (`-d` or `php.ini`; PHP without a `php.ini`
    defaults to `EGPCS`). Making `Env::get` prefer
    the process environment would remove the option (L-04, owner's call).
  - The seed's session INSERT repeats `SessionStore`'s columns, and its template choice repeats
    the flow engine's (a test pins it to `display()->even`).
