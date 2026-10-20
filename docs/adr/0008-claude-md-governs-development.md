# 0008 — `CLAUDE.md` governs development rules

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The draft plan had its own commit format (`feat(p1-05): …`) and working agreements, which
conflicted with the shared `CLAUDE.md` standard (`<ID>: <imperative summary>`).

## Decision

`CLAUDE.md` holds the development rules: commits, branches, roles, ADRs, task statuses and
guardrails. `docs/PLAN.md` defines what to build. Commit subjects are `<ID>: <imperative summary>`
with work IDs `P<phase>-<nn>` or `L-<nn>`, e.g. `P1-05: Add ConvertService and /convert`.
Project-specific exceptions go in the "Deviations" section of `CLAUDE.md`, not into the shared
template text.

## Consequences

- When the plan and `CLAUDE.md` disagree, `CLAUDE.md` wins and the conflict is raised.
- Only the owner edits `CLAUDE.md` (GR-005).
- During Phase 0 the git rules are relaxed until the repository exists (deviation 1).
