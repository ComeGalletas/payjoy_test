# Architecture decision records

One file per decision: `NNNN-<slug>.md`, numbered in order, never renumbered. An accepted ADR
beats `docs/PLAN.md` until a later ADR supersedes it. To change a decision, write a new ADR that
names the one it supersedes and set the old one's status to `Superseded by NNNN`. When a new ADR
replaces only part of an old one, the old one stays `Accepted` with a note naming the part and the
new ADR, and the affected sentence in its body points to the new ADR. A decision that is extended
or refined without being reversed gets a dated "Amendment" section in the same ADR; its status
becomes `Accepted (amended YYYY-MM-DD)`, and the changed sentences in its body point to the
amendment. When a later ADR changes only a consequence or known limit, with no change to the
decision, the status becomes `Accepted (note YYYY-MM-DD)` and the changed sentence points to the
later ADR.

| ADR | Title | Status |
|---|---|---|
| [0001](0001-php-without-framework.md) | PHP 8.3 without a framework; Composer dependency set | Accepted (amended twice 2026-10-21; note and amendment 2026-10-22) |
| [0002](0002-sqlite-pdo-postgres-ready.md) | SQLite via PDO, Postgres-ready through `Database` + `Dialect` | Superseded by 0010 |
| [0003](0003-static-flow-deterministic-mvp.md) | Static YAML flow and deterministic parsing for the MVP; LLM deferred | Accepted |
| [0004](0004-typebot-optional-frontend.md) | Typebot as optional Phase 3 front-end; static flow as fallback | Accepted (amended 2026-10-21) |
| [0005](0005-docker-primary-local-required.md) | Local install primary through Phase 2; Docker compose from Phase 3 | Accepted (amended; note 2026-10-22) |
| [0006](0006-amount-is-total.md) | `amount` is the total USD price | Accepted |
| [0007](0007-full-precision-display-rounding.md) | Full precision end to end; display-only rounding | Superseded by 0011 |
| [0008](0008-claude-md-governs-development.md) | `CLAUDE.md` governs development rules and commit format | Accepted |
| [0009](0009-doc-layout-with-log.md) | Doc layout per `CLAUDE.md`, plus `docs/LOG.md` | Accepted |
| [0010](0010-portable-pdo-postgres-deferred.md) | Portable PDO now, Postgres support deferred | Accepted |
| [0011](0011-minor-units-floor-split.md) | Minor units, floor split and English display | Accepted (storage clause superseded by 0017; amended 2026-10-21, twice 2026-10-22) |
| [0012](0012-upstream-latest-local-math.md) | Upstream: one `/latest/USD` call per refresh, local math, stale fallback | Accepted (amended 2026-10-21, twice 2026-10-22) |
| [0013](0013-amount-input-parsing.md) | Amount input: strict API, lenient bot | Accepted (amended 2026-10-21) |
| [0014](0014-phase1-scope-cut.md) | Phase 1 scope cut | Accepted |
| [0015](0015-co-lead-dual-approval.md) | Co-lead dual approval; builder, tester and judge per task | Accepted (amended 2026-10-21) |
| [0016](0016-request-log-and-ip-blocking.md) | Request log and IP blocking | Accepted (amended twice 2026-10-21, twice 2026-10-22; note 2026-10-22) |
| [0017](0017-integer-fixed-unit-storage.md) | Store money and rates as integers in fixed units | Accepted (amended twice 2026-10-21) |
| [0018](0018-test-network-isolation.md) | Keeping tests off the network | Accepted (amended 2026-10-22) |
| [0019](0019-schema-apply-policy.md) | Migrate explicitly; the app fails closed on an old schema | Accepted (amended 2026-10-22) |
| [0020](0020-migration-versioning.md) | Append-only migration list; `schema.sql` is generated | Accepted |
| [0021](0021-flow-engine-bot-contract.md) | Flow engine and bot message contract | Accepted (notes and amendments 2026-10-22) |
| [0022](0022-widget-serving-and-embed.md) | Built-in widget: serving, embedding and non-JSON responses | Accepted (amended 2026-10-22) |
| [0023](0023-reports-funnel-and-csv.md) | Reports: period, cohort funnel, allow-listed labels and CSV | Accepted |
| [0024](0024-demo-seed-data.md) | Demo seed data | Accepted |

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
