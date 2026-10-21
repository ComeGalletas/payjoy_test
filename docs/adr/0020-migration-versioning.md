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
  at 1; the applied version is `app_state.schema_version`. Only a database without `app_state` is
  version 0; a missing or non-integer `schema_version` value throws `SchemaCorrupt`, so the app
  answers 500 and `migrate.php` and `--prune` exit 1 (the fail-closed startup of ADR 0019).
- Each migration runs in one transaction; from migration 2 on (which creates `app_state`) the
  version update is in the same transaction, so a failure leaves the previous version intact.
  Migration 1 records no version of its own.
- **Append-only.** A shipped migration is never edited; changes go in a new migration. A test pins
  a sha256 of each shipped migration's statements and fails the gate if one changes.
- `api/db/schema.sql` is generated (`php api/bin/migrate.php --dump`) from the migrations and never
  applied; a test fails if it drifts.
- Migrations 2+ use plain `CREATE` (no `IF NOT EXISTS`), so a wrong version reading fails loudly.
- Two migrate runs at once are not coordinated; run one at a time (ADR 0019).
- **Postgres (L-03).** Migrations 1–3 use `INTEGER PRIMARY KEY` (`events.id`, `requests.id`), a
  SQLite form; migration 3 also rebuilds a table. L-03 translates dialect when it applies
  migrations (a `Dialect` step), never by editing shipped migrations.

## Consequences

- Fixing a mistake in a shipped migration means adding a corrective migration.
- The pin test must be updated only when a new migration is appended, never for an old one.
- Known gap: today the pin test doesn't fail when a migration ships without a pin. The task that
  adds migration 4 must add that check (pins count equals migrations count) and replace tests that
  hard-code version 3 and "seven tables".
