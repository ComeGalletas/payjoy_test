# 0020 — Append-only migration list; `schema.sql` is generated

- **Status:** Accepted
- **Date:** 2026-10-21
- **Deciders:** owner

## Context

P1-02 replaced P1-01's single `schema.sql` with migrations so that existing databases upgrade.
The review found nothing stopped someone editing a shipped migration and regenerating
`schema.sql`, which would pass every test while leaving existing databases different from new
ones.

## Decision

- `api/src/Db/Migrations.php` holds one ordered list. Versions are consecutive integers starting
  at 1; the applied version is `app_state.schema_version`. A database without `app_state` is
  version 0.
- Each migration runs in one transaction together with its version update, so a failure leaves
  the previous version intact.
- **Append-only.** A shipped migration is never edited; changes go in a new migration. A test pins
  a sha256 of each shipped migration's statements and fails the gate if one changes.
- `api/db/schema.sql` is generated (`php api/bin/migrate.php --dump`) from the migrations and never
  applied; a test fails if it drifts.
- Migrations 2+ use plain `CREATE` (no `IF NOT EXISTS`), so a wrong version reading fails loudly.
- Two migrate runs at once are not coordinated; run one at a time (ADR 0019).
- **Postgres (L-03).** Migrations 1 and 3 use SQLite-specific forms (`INTEGER PRIMARY KEY`, the
  table rebuild). L-03 translates dialect when it applies migrations (a `Dialect` step), never by
  editing shipped migrations.

## Consequences

- Fixing a mistake in a shipped migration means adding a corrective migration.
- The pin test must be updated only when a new migration is appended, never for an old one.
