# 0010 — Portable PDO now, Postgres support deferred

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner
- **Supersedes:** 0002

## Context

ADR 0002 required a `Dialect` layer, two generated schemas and a Postgres test suite in Phase 1,
while Postgres metrics are only a stretch item (L-03). The review flagged this as building for a
need that doesn't exist yet.

## Decision

- Phase 1 uses SQLite through PDO behind a `Database` factory reading `DB_DSN`, `DB_USER`,
  `DB_PASS`. SQLite runs with WAL and a 5 s busy timeout; the file lives in `api/var/`, outside
  `public/`.
- SQL stays portable: no SQLite-only functions, no JSON functions in queries (JSON is TEXT,
  decoded in PHP), UTC epoch integers instead of date functions, upserts as select-then-write in a
  transaction.
- `Dialect`, `db/schema.pgsql.sql`, the `fx_metrics` Postgres database and
  `tests/Integration/PostgresTest.php` move to L-03.

## Consequences

- Phase 1 has one schema and one migration path.
- Moving to Postgres later is mostly additive, because no query depends on SQLite features.
- Reviews check new SQL for SQLite-only constructs.
