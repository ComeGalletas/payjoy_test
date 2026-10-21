# TODO

Checklist of the work IDs in `docs/PLAN.md` §10, which holds each task's spec and acceptance test.
The first unchecked item is the current work. From Phase 1 on, the lead ticks an item when its PR
is merged and adds its end status.

**Current phase:** Phase 0 done; Phase 1 next
**Next item:** P1-01

## Phase 0 — Pre-work

- [x] P0-01 Adapt `CLAUDE.md` — DONE (2026-10-20)
- [x] P0-02 Doc set and initial ADRs — DONE (2026-10-20)
- [x] P0-03 Install prerequisites (PHP 8.3 + extensions, CA bundle, Composer, git, make) — DONE_WITH_CONCERNS (2026-10-20; see LOG)
- [x] P0-04 Repository, Composer/PHPUnit skeleton, Makefile, hooks, branch protection — DONE (2026-10-20; PR #1, follow-ups in P0-06)
- [x] P0-05 Owner adds API key and admin token to `.env` — DONE (2026-10-20)
- [x] P0-06 Guard hardening follow-ups from the P0-04 review — DONE (2026-10-20; PR #3; builder's concerns settled by owner decision and L-04)

## Phase 1 — Deterministic MVP

- [ ] P1-01 Foundation
- [ ] P1-02 Schema and migrations
- [ ] P1-03 Validation and `AmountParser`
- [ ] P1-04 Rates: client, cache, breaker
- [ ] P1-05 Convert and `GET /convert`
- [ ] P1-06 Flow engine and `POST /bot/message`
- [ ] P1-07 Widget
- [ ] P1-08 Reports and health
- [ ] P1-09 Seed data and docs
- [ ] P1-10 End-to-end run — deliver MVP

## Phase 2 — LLM normalizer

- [ ] P2-01 `Normalize/` core
- [ ] P2-02 Provider adapters
- [ ] P2-03 `POST /normalize` and flow integration
- [ ] P2-04 LLM report section
- [ ] P2-05 LLM health check

## Phase 3 — Typebot, Docker and operator extras

- [ ] P3-01 Docker compose and `INSTALL_DOCKER.md`
- [ ] P3-02 Config API
- [ ] P3-03 `POST /events`
- [ ] P3-04 Capability catalog
- [ ] P3-05 Typebot flow
- [ ] P3-06 Loader and fallback
- [ ] P3-07 Typebot contract test
- [ ] P3-08 Dashboard
- [ ] P3-09 Daily rollups
- [ ] P3-10 APCu cache
- [ ] P3-11 `ADDING_A_FEATURE.md`

## Later / stretch

- [ ] L-01 `bin/export-typebot.php`
- [ ] L-02 Telegram / WhatsApp channel
- [ ] L-03 Postgres metrics
- [ ] L-04 Tooling backlog (cache v5, atomic hook write, `FORCE=0`, squash-merge subjects, backup pruning)
