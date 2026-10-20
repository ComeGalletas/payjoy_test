# 0014 — Phase 1 scope cut

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The review found Phase 1 built roughly twice what an MVP needs. Several items have no consumer
until Typebot arrives: `POST /events` (no external channel), the capability catalog (used by the
Typebot contract test), the config API, the dashboard, nightly rollups (no scheduler on Windows or
`php -S`) and APCu.

## Decision

Phase 1 shrinks from 13 to 10 tasks. These move to Phase 3: config API and `config` table,
`POST /events`, `GET /capabilities` and `docs/CAPABILITIES.md`, `GET /reports/dashboard`,
`daily_rollups` and `bin/rollup.php`, APCu. The currency-country map is a PHP array instead of a
table. Config is read-only defaults in `Config/defaults.php` until Phase 3. Phase 1 reports read
`events` directly.

## Consequences

- The MVP is smaller and faster to deliver and review.
- Changing a setting in Phases 1–2 means editing `Config/defaults.php` and redeploying.
- Reports may get slower as `events` grows; rollups in P3-09 fix that.
