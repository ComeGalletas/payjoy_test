# 0009 — Doc layout per `CLAUDE.md`, plus `docs/LOG.md`

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The draft plan used `docs/DECISIONS.md` for decisions and `docs/LOG.md` for progress. `CLAUDE.md`
uses `docs/adr/`, `docs/PLAN.md`, `docs/TODO.md`, `CONTEXT.md`, `memory/MEMORY.md` and
`docs/GUARDRAILS.md`, and has no log.

## Decision

Use the `CLAUDE.md` layout. Decisions are ADRs in `docs/adr/`; there is no `docs/DECISIONS.md`.
Keep `docs/LOG.md` as a dated journal of progress and anomalies (quota usage, upstream errors,
test failures, tool versions), recorded as deviation 2 in `CLAUDE.md`.

## Consequences

- Agents read the files in the order `CLAUDE.md` gives, then check `docs/LOG.md` for recent
  anomalies.
- `docs/CAPABILITIES.md` stays generated, never hand-edited.
