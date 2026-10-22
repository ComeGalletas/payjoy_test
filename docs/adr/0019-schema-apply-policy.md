# 0019 — Migrate explicitly; the app fails closed on an old schema

- **Status:** Accepted (amended 2026-10-22: `GET /health`, see "Amendment")
- **Date:** 2026-10-21
- **Deciders:** owner

## Context

P1-01 applied the schema on every first connection. P1-02 adds migrations, including one that
rebuilds `events`. Running a table rebuild inside a web request, or from several requests at once,
is unsafe, and on Postgres the web user shouldn't need DDL rights. The P1-02 builder measured the
cost (PR #8; Windows 11, PHP 8.3.33, SQLite 3.53.2, file database, median of 2,000 connections):
about 1.15 ms to connect, 1.28 ms with a version check, 1.31 ms with P1-01's idempotent apply, so
the choice is about safety, not speed.

## Decision

- Schema changes run only through `php api/bin/migrate.php`, at install and on every deploy or
  pull, one run at a time.
- On its first connection the app checks `app_state.schema_version`
  (`Migrator::assertCurrent()`). A database behind the code is refused: every request gets 500
  `INTERNAL` (except `GET /health`, see "Amendment"), and the error log names `SchemaOutOfDate`.
  The app never migrates on its own.
- The app opens the database before routing (P1-11 needs it for the request log and block check
  anyway), so an unopenable database also gives 500 on every request. This is intended; INV-07
  still covers a failed metrics write on an open database.
- A database **ahead** of the code is allowed, so a code rollback keeps serving — but only when
  every migration in between is expand-only (adds tables, columns or indexes). A migration that
  renames or drops something, like migration 3 (`amount_usd` → `amount_usd_cents`), makes older
  code lose writes silently (INV-07 swallows them). Such migrations need a deploy order: stop the
  app, back up the database, migrate, start the new code; and no rollback past them without
  restoring the backup.

## Consequences

- `docs/INSTALL_LOCAL.md` (P1-09) documents: run migrate after `composer install` and before
  starting the server; a 500 with `SchemaOutOfDate` in the log means "run migrate"; back up
  `api/var/app.db` before migrating; schedule a daily `--prune`.
- `/health` couldn't report a status body when the database is behind or unopenable; P1-08
  routed `GET /health` before the database opens (see "Amendment").
- Every non-expand migration is called out in its PR and in `docs/LOG.md`.

## Amendment (2026-10-22): `GET /health` answers on an unusable database

Recorded from P1-08 (PR #23, ADR 0023); owner decision 2026-10-22, settling the question this ADR
left to P1-08.

- `public/index.php` opens the database before routing for every request except `GET /health`.
  Every other path, and any other method on `/health`, still fails closed with 500 `INTERNAL`.
- `/health` checks the database itself. It is healthy when the database opens, its schema version
  is current or ahead, and a write probe (`UPDATE app_state SET value = value` in a transaction
  that is rolled back) succeeds. A healthy public view is the envelope with `status: "ok"` and
  nothing else; otherwise the public view answers 503 with the new error code `UNHEALTHY` ("The
  service is temporarily unavailable. Please try again later."), never a raw 500.
- Only an `Authorization` header with the Bearer scheme selects the admin view. A wrong or
  malformed Bearer header gets 401; any other scheme, or an empty header, gets the public view.
  The admin view always answers 200, with `status` (`ok` or `unhealthy`) and the database, rate
  table, breaker (including `failures`) and quota details (ADR 0012 second amendment).
- INV-07 and INV-08 still hold: on a broken database, `/health` still reaches the Kernel, whose
  `requests` write fails and is swallowed.
- **Known limits:**
  - Each public `/health` takes SQLite's write lock twice (the probe and the `requests` row), so a
    lock held past the 5 s busy timeout, for example by a `--prune` or a sweep, reads as
    unhealthy.
  - `HEAD /health` gets the router's 404, which counts toward `block.error_responses`, so
    monitors must use `GET` (P1-09).
