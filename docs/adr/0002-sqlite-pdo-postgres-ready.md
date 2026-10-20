# 0002 — SQLite via PDO, Postgres-ready

- **Status:** Superseded by 0010
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

Metrics, rate cache, runtime config and bot sessions need storage. SQLite needs no server and suits
the local install; the Docker stack already runs Postgres for Typebot, and metrics may move there
later.

## Decision

All DB access goes through PDO behind a `Database` factory reading `DB_DSN`, `DB_USER`, `DB_PASS`.
SQL stays portable; dialect differences (JSON extraction, `INSERT … ON CONFLICT`, date truncation)
live in `SqliteDialect` / `PostgresDialect`. `bin/migrate.php` holds one migration list that
produces both `db/schema.sql` and `db/schema.pgsql.sql`. Postgres metrics use their own database
(`fx_metrics`), never Typebot's.

## Consequences

- No SQLite-only functions in hot paths; reviews check for them.
- `tests/Integration/PostgresTest.php` runs the suite against Postgres when `TEST_PG_DSN` is set.
- Switching is a DSN change plus a migration run.
