# 0015 — Co-lead dual approval; builder, tester and judge per task

- **Status:** Accepted (amended 2026-10-21: merge order, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The owner wants Claude to act as co-lead: validate and judge the work, with development moving
only when both leads approve. Building, testing and judging by the same agent lets mistakes grade
themselves. The repository lives on a single GitHub account (`ComeGalletas`), so GitHub cannot
record a second approving review on PRs that account opens.

## Decision

- **Co-leads:** the owner and Claude (lead role, main checkout). Neither alone moves work forward.
- **Per work ID, three separate subagents:**
  1. **Builder** (worker role, own git worktree): implements the task on branch
     `<prefix>/<id>-<slug>` (prefixes `feat`, `fix`, `docs`, `chore`), with tests in the same
     commit, pushes and opens a PR. It never merges.
  2. **Tester:** checks out the PR head in a fresh clone, runs `make init`, `make check` and the
     task's acceptance test, tries edge cases and failure paths from the spec, and reports
     pass/fail with output. It doesn't fix code.
  3. **Judge:** reviews the diff against `docs/PLAN.md`, `CLAUDE.md`, the ADRs and `CONTEXT.md`;
     returns a verdict (approve / changes needed) with reasons. It doesn't fix code.
- **Approvals:** Claude reads all three reports and posts its co-lead verdict as a PR comment.
  The owner gives theirs in chat or as a PR comment. When both approve, the lead merges with
  `gh pr merge <n> --merge --match-head-commit <reviewed sha>` and ticks `docs/TODO.md`.
  "Changes needed" from either lead sends the task back to a builder, and the tester and judge run
  again on the new head.
- **GitHub settings:** `main` requires a PR and passing CI, with admins included, and 0 required
  reviews (a single account can't review its own PRs). The approvals above are the review record.
- A milestone (end of a phase) gets one extra tester + judge pass over the whole phase.

## Consequences

- Every merge has an independent test report and review on record in the PR.
- More agent runs per task; small tasks still get all three roles.
- If a second GitHub account is added later, switch to required reviews and supersede this ADR.

## Amendment (2026-10-21): merge order

**Context.** PRs #8 and #10 were merged on GitHub before the co-leads' approval comment was posted.
The account is shared, so the record can't show who merged, and the merge landed before the
approval it depended on was on the PR.

**Decision** (owner).
- **Builder PRs:** after the owner approves in chat, the lead posts the approval comment first,
  then merges with `gh pr merge <n> --merge --match-head-commit <reviewed sha>`. The owner doesn't
  merge builder PRs on GitHub.
- **The lead's own PRs** (docs close-outs): the owner merges them, after the verdicts (GR-013).

**Consequences.** Every builder merge has its approval comment on the PR before the merge commit.
