# Architecture decision records

One file per decision: `NNNN-<slug>.md`, numbered in order, never renumbered. An accepted ADR
beats `docs/PLAN.md` until a later ADR supersedes it. To change a decision, write a new ADR that
names the one it supersedes and set the old one's status to `Superseded by NNNN`.

| ADR | Title | Status |
|---|---|---|
| [0001](0001-php-without-framework.md) | PHP 8.3 without a framework; Composer dependency set | Accepted |
| [0002](0002-sqlite-pdo-postgres-ready.md) | SQLite via PDO, Postgres-ready through `Database` + `Dialect` | Superseded by 0010 |
| [0003](0003-static-flow-deterministic-mvp.md) | Static YAML flow and deterministic parsing for the MVP; LLM deferred | Accepted |
| [0004](0004-typebot-optional-frontend.md) | Typebot as optional Phase 3 front-end; static flow as fallback | Accepted |
| [0005](0005-docker-primary-local-required.md) | Local install primary through Phase 2; Docker compose from Phase 3 | Accepted (amended) |
| [0006](0006-amount-is-total.md) | `amount` is the total USD price | Accepted |
| [0007](0007-full-precision-display-rounding.md) | Full precision end to end; display-only rounding | Superseded by 0011 |
| [0008](0008-claude-md-governs-development.md) | `CLAUDE.md` governs development rules and commit format | Accepted |
| [0009](0009-doc-layout-with-log.md) | Doc layout per `CLAUDE.md`, plus `docs/LOG.md` | Accepted |
| [0010](0010-portable-pdo-postgres-deferred.md) | Portable PDO now, Postgres support deferred | Accepted |
| [0011](0011-minor-units-floor-split.md) | Minor units, floor split and English display | Accepted |
| [0012](0012-upstream-latest-local-math.md) | Upstream: one `/latest/USD` call per refresh, local math, stale fallback | Accepted |
| [0013](0013-amount-input-parsing.md) | Amount input: strict API, lenient bot | Accepted |
| [0014](0014-phase1-scope-cut.md) | Phase 1 scope cut | Accepted |
| [0015](0015-co-lead-dual-approval.md) | Co-lead dual approval; builder, tester and judge per task | Accepted |

## Template

```markdown
# NNNN — Title

- **Status:** Proposed | Accepted | Superseded by NNNN
- **Date:** YYYY-MM-DD
- **Deciders:** owner
- **Supersedes:** NNNN (if any)

## Context
## Decision
## Consequences
```
