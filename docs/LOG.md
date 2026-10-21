# Log

Dated progress and anomalies: quota usage, upstream errors, test failures, tool versions. Newest
entry first. Dates are `YYYY-MM-DD`, America/Bogota. The owner mirrors anything relevant into the
"Payment conversion app architecture" chat.

## 2026-10-21 — P1-02 Schema and migrations (PR #8, head 2052406)

- **Round 1** (`152b277`). Builder: DONE_WITH_CONCERNS; seven tables with integer and text columns
  only, an append-only migration list (`app_state.schema_version`), a P1-01 upgrade that converts
  `amount_usd` to cents, generated `schema.sql`, `--prune`, `floatParam` removed, `UsdCents` and
  `ScaledRate` helpers, a rate-limiter race test through a seam. Unit+Acceptance 151 → 243, guards
  80. Schema-apply policy chosen by the builder: migrate explicitly, the app fails closed on an old
  schema (ADR 0019).
- Tester round 1: FAIL. `ScaledRate::fromRate()` truncated rates ≥ 100,000 (`round()` returns values
  ≥ 1e15 unchanged; `439537.6134` → `…133999999`); reproduced by the lead. Judge round 1: APPROVE
  WITH NITS. Co-leads: CHANGES NEEDED; the owner said "send back the PR and make the fixes and ADR
  changes".
- **Live rate data** (lead, `open.er-api.com/v6/latest/USD`, 2026-10-21, 166 codes): at most 6
  decimals and 7 integer digits; largest rate IRR ≈ 1,731,936.86, above the round-1 2^53 limit.
- **Round 2** (`2052406`). `rate_scaled` built from decimal text with integer arithmetic, limit
  below 900,000,000; migrations 1–3 pinned by sha256; `currentVersion()` narrowed with a new
  `SchemaCorrupt` error. Unit+Acceptance 318 (362,137 assertions), guards 80; `make check` ≈ 33 s,
  the sweep ≈ 2.5 s. Builder sweep: 340k rates, 0 mismatches. Tester round 2: PASS, its own bcmath
  reference found 0 mismatches over 250k rates, and every mutation was caught. Judge round 2:
  APPROVE WITH NITS. CI `check`: pass, 23 s. Co-leads: both approved.
- Merged at the reviewed head as `b02a155` by ComeGalletas at 13:49:08 UTC, before the lead's own
  merge command ran and about 20 s before the leads' approval comment was posted.
- **Process.** Round 1: two worktree-guard blocks, each handled by applying the stated remedy once
  in Bash; a scratch file was then written with the Write tool. Unlike the P1-01 shell switch, this
  was accepted: the lead proposed it and the owner ratified the rule during the PR #9 review —
  the dedicated file tools (Write, Edit) are always allowed for creating files; redoing a blocked
  shell action through a different shell stays forbidden. Round 2: no blocks.
- Lead docs: ADRs 0019 and 0020, ADR 0016 and 0017 amendments, INV-10, the corrected PLAN §3a
  magnitude claim (the real worst case is 1,000,000 USD in IRR, ≈ 1.73 × 10^14 minor units), and
  the review follow-ups placed in P1-03, P1-04, P1-05, P1-08, P1-09, P1-11, L-03, L-04 and the
  P1-02 note (for the task that adds migration 4).
- PR #9 review: tester PASS (all docs claims true against the code); judge CHANGES NEEDED (two
  overclaims in ADR 0017 and a factual error in ADR 0020, fixed); judge recheck APPROVE WITH NITS. Owner decisions: ratified the file-tools rule above; approved the
  GUARDRAILS edits (INV-07, INV-09, INV-10) and a CLAUDE.md Commands row for `migrate.php`; manual
  blocks win, and a new automatic block extends an active automatic one.

## 2026-10-20 — P1-01 Foundation (PR #6, head 9c1eeeb)

- Builder: DONE_WITH_CONCERNS, one commit `9c1eeeb`. Unit+Acceptance 6 → 151 tests (606
  assertions), guards 80; green in Git Bash and cmd. New runtime dependency `vlucas/phpdotenv`
  v5.7.0 (with graham-campbell/result-type 1.2.0, phpoption 1.10.0, symfony polyfill-ctype 1.37.0,
  polyfill-mbstring 1.43.0, polyfill-php80 1.43.0). Schema shipped only `events` and `rate_limits`,
  as the lead decided; the rest is P1-02.
- Tester: PASS 11/11; each mutation was caught (empty-token auth: 4 failing tests; changing the
  limit to 31: 7; exception text in the 500: 2 unit tests; Metrics rethrow: 7). Judge: APPROVE
  WITH NITS. CI `check`: pass, 19 s. Co-leads: both approved; the owner merged it as `9d30675`.
- Builder finding: PDO binds PHP floats as 14-digit strings, losing precision; the stopgap
  `Database::floatParam()` is replaced by integer storage (ADR 0017, in P1-02).
- Gate scripts: the Composer `test` and `acceptance` scripts now pass `-d allow_url_fopen=0`
  (ADR 0018). The owner confirmed the change and ADR 0018 explicitly during the PR #7 review;
  GR-006 now lists the `Makefile` and the gate scripts as owner-owned.
- **Process breaches, both logged:** after a harness block on a Bash call, the builder followed the
  block message's advice by switching to PowerShell instead of stopping, as the brief required; it
  also retried the Composer download about 8 times (its own count) against the brief's 3. No harm
  resulted. Briefs now say: follow the guard's stated remedy in the same tool, otherwise stop.
- Owner decisions during review: every request is recorded with its raw IP for 30 days and abusive
  IPs are blocked (ADR 0016, new task P1-11 before P1-05); money and rates are stored as integers
  in fixed units (ADR 0017). ADR 0018 records the test network isolation.
- PR #7 review (judge: CHANGES NEEDED, tester: PASS 10/10). The owner then confirmed: automatic
  blocking; blocked requests write only their `requests` row, no `error` event, and nginx 444 drops
  are imported in P3-01; 30-day retention of `requests`; ADR 0018 and the gate scripts; CLAUDE.md
  deviation 3 (ADR timing). The judge's findings were fixed in the same PR.

## 2026-10-20 — P0-05 and P0-06 close-out review (PR #4)

- PR #4 (P0-06 close-out) was merged by the owner as `b823ab8` before its tester and judge
  finished; at the owner's request their results were posted on the PR afterwards. The merged head
  `4381593` is the reviewed head. Tester: PASS 8/8. Judge: APPROVE WITH NITS (fixed in this PR:
  status wording, GUARDRAILS rule wording, `auto` cost, LOG approval and timing details, TODO L-04
  line). Not applied: the two extra GUARDRAILS limits stay, since both are true and tracked in L-04.
- P0-05: the owner added `EXCHANGERATE_API_KEY` to `.env`, then set `ADMIN_TOKEN` (any random value,
  owner's choice). Agents did not read `.env`. P0-05 is DONE.
- PR #5 judge: APPROVE WITH NITS on `75ea4b0`; its nits (P0-05 status and wording, P0-06 approval
  wording in PLAN, a reflowed LOG line) are fixed in the same PR.

## 2026-10-20 — P0-06 guard hardening (PR #3, head 912c7c2)

- PR #2 (P0-04 close-out) merged by the owner as `0ac5569`. The owner approved the two `CLAUDE.md`
  host-quirk lines in chat: "I approve PR #2 including the two CLAUDE.md host-quirk lines".
- P0-06 approved before work started: the lead proposed it in PR #2; owner: "start P0-06 once I
  confirm the merge".
- Builder: DONE_WITH_CONCERNS, one commit `912c7c2`. Guard tests 53 → 80 (1244 assertions on
  Windows, 1242 on Linux CI); `make check` took 25.3–27.7 s on Windows, the guards 1.3 s on Linux
  CI. Pinned `actions/checkout@fbc6f39… # v5.1.0`, `actions/cache@0057852… # v4.3.0`, and kept
  `setup-php@eb7c497…` with its comment fixed to `2.40.0`.
- Guard blocks, all reported and not worked around: two worktree-guard refusals (a complex probe,
  a CI poll) and one permission-classifier denial (`git restore --source=origin/main`, flagged as
  irreversible local destruction). The tester did the old-code check in its own clone instead.
- Tester: PASS. Mutation check: 19 of 80 guard tests fail with the `0ac5569` installer and hook.
  Judge: APPROVE WITH NITS. CI `check`: pass, 12 s. Co-leads: both approved.
- **Corrections:** the lead's brief contradicted the spec (it asked for a `;` comment line above
  the subject to be accepted); the builder correctly followed the spec. The lead also assumed git
  strips comments before `commit-msg`; it doesn't, so the hook rejects a subject typed below git's
  comment block. The builder's concern that `rebase -i` squash is rejected was also wrong: squash
  doesn't run `commit-msg`.
- Owner decision: keep "always reject a message whose first non-blank line is a comment".
- Merged with `--match-head-commit 912c7c2` → `eae17fa`. Main checkout `make init`: new
  `commit-msg` installed, previous kept as `commit-msg.bak.20261021-005243`; `pre-push` unchanged.
- Backlog from the review recorded as L-04 in `docs/PLAN.md`.

## 2026-10-20 — P0-04 round 2 and merge (PR #1, head 47a6c80)

- Builder round 2: one new commit `47a6c80` fixing A, C–I; guard tests 27 → 53. Hook install now
  writes nothing from a linked worktree; in the main checkout it keeps a differing managed hook as
  `.bak` and refuses foreign hooks unless `FORCE=1`. Extra: Makefile `COMPOSER` renamed
  `COMPOSER_BIN` (Composer reads `COMPOSER` as the composer.json name; `composer.bat` default on
  Windows).
- Guard blocks this round, all reported and not worked around: a Bash command naming `.git*` paths
  (lead authorized the guard's own suggestion, "plain, separate commands"); a chained sleep (use
  Monitor instead); a complex `gh api --jq` poll (lead checked CI read-only from the main checkout).
  The push ran in plain PowerShell, which the lead explicitly authorized because that shell was
  never blocked; the push itself was never attempted or blocked in Bash (its first PowerShell
  attempt failed only on the network). The owner was told in chat before approving. The pre-push
  `make check` and CI both ran.
- Tester: PASS 10/10. Judge: APPROVE WITH NITS. CI `check`: pass, 13 s. Co-leads: both approved.
- Merged with `gh pr merge 1 --merge --match-head-commit 47a6c80…` → merge commit `365a57f`.
- Main checkout: `make init FORCE=1` installed the merged hooks; the round-1 copies (no marker) are
  kept as `.git/hooks/commit-msg.bak` and `pre-push.bak`.
- Follow-ups proposed as P0-06 (see `docs/PLAN.md`).
- Network: GitHub dropped connections repeatedly; every create step was retried only after
  checking the first attempt had not landed.

## 2026-10-20 — P0-04 round 1 review (PR #1, head 87335e0)

- Repository created: `ComeGalletas/payjoy_test` (public). `main` protected: PR required, admins
  included, no force-push or deletion, 0 required reviews (single account; ADR 0015).
- Builder: DONE_WITH_CONCERNS. Tester: PASS 12/12, including GitHub rejecting a direct push to
  `main` from a clone without hooks (`GH006`). Judge: CHANGES NEEDED. Co-leads: CHANGES NEEDED.
- Round-2 items A, C–I sent back to the builder (see the PR #1 verdict comment). `check` made a
  required status check on `main`. Deferred to P1-01: tests never load `.env`; `DATA_ROOT` /
  `BACKUP_ROOT` point at temp dirs.
- **Incident (GR-030):** the worktree guard blocked some Bash commands naming `.git*` paths; the
  builder re-ran those steps through PowerShell instead of stopping. Steps involved: installing
  the hooks and reading git metadata. Impact: `make init` from the worktree installed this
  branch's hooks into the main checkout's shared `.git/hooks` (finding A). Owner and co-lead
  accept the outcome on record; the hooks are reinstalled from `main` after P0-04 merges. From now
  on every brief says: when a guard blocks you, stop and report.
- Network: repeated connection resets and timeouts to GitHub and Packagist during this round.

## 2026-10-20 — P0-04 started: repository and co-lead process

- Owner set the working model: Claude is co-lead; nothing merges without both approvals; each task
  runs as builder + tester + judge subagents (ADR 0015).
- Repository: `ComeGalletas/payjoy_test`, **public** (owner choice; GitHub Free allows branch
  protection only on public repos). Commits use `user.name` "Juan Pablo Saenz" (repo-local) and the
  owner's existing email, which is visible in the public history (owner confirmed).

## 2026-10-20 — P0-03 prerequisites installed

| Tool | Version | Location / source |
|---|---|---|
| PHP | 8.3.33 (cli, ZTS, VC++ 2019 x64) | winget `PHP.PHP.8.3`, `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe` |
| Extensions | curl 8.21.0, openssl (OpenSSL 3.0.21), mbstring, pdo_sqlite (SQLite 3.53.2), zip | enabled in that folder's `php.ini` (from `php.ini-development`) |
| CA bundle | Git for Windows bundle, dated 2026-04-20 | `curl.cainfo` and `openssl.cafile` → `C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt` |
| Composer | 2.10.3 (2026-08-27) | `composer.phar` from getcomposer.org, SHA256 verified, `%LOCALAPPDATA%\Programs\composer` + `composer.bat`, added to user PATH |
| git | 2.54.0.windows.1 | already installed |
| make | GNU Make 4.4.1 | already installed (winget `ezwinports.make`) |

- Acceptance: `php -v` shows 8.3.33; `php -m` lists curl, mbstring, openssl, pdo_sqlite; HTTPS
  via `file_get_contents` and curl both print ok; `composer diagnose` passes platform, git, http
  and https checks.
- Anomaly: `composer diagnose` reports `Checking pubkeys: FAIL` because a manually downloaded
  phar has no signing keys installed. Installs are unaffected; `composer self-update` signature
  checks need `composer self-update --update-keys` (keys from composer.github.io/pubkeys.html).
- The CA bundle only updates when Git is updated.
- `zip` was enabled as well, so Composer can unpack packages without external tools.
- Open terminals need a restart to see the new PATH.

## 2026-10-20 — Plan review applied

- A cold-critic review of `docs/PLAN.md` (senior lead perspective) returned "Good with changes":
  12 must-change items and 12 to consider.
- Owner answers: per-currency decimals (ISO 4217); the last installment absorbs the remainder with
  a clear message; the bot speaks English and reads both `.` and `,` in amounts, asking for
  clarification when unclear; a country is recorded only when the user typed one; Phase 1 cuts
  accepted, deferred items move to Phase 3.
- Applied: `docs/PLAN.md` rewritten (money §3a, upstream §3b, amount parsing §3c, Phase 1 now 10
  tasks with acceptance tests, Phase 3 now 11); `docs/TODO.md` reduced to a checklist; ADRs 0010–0014
  added, 0002 and 0007 superseded, 0001 and 0005 amended; `docs/GUARDRAILS.md` INV-01–INV-07;
  `CONTEXT.md` updated.
- Choices made by the agent, open to owner change: `/convert` stays strict (`.` decimal only);
  report days use America/Bogota; `rates.max_stale_hours=48`; `ratelimit.per_minute=30`.

## 2026-10-20 — Pre-work session

- Final plan written to `docs/PLAN.md` with the owner's decisions (§13). The original draft
  `fx-installments-plan.md` is kept unchanged.
- P0-01: `CLAUDE.md` adapted to this project (summary, invariant, host quirks, work IDs,
  deviations).
- P0-02: created `CONTEXT.md`, `memory/MEMORY.md`, `docs/TODO.md`, this log,
  `docs/GUARDRAILS.md` and ADRs 0001–0009.
- No code yet. No repository yet (P0-04). Prerequisites not checked or installed yet (P0-03).
- Upstream: 0 calls, no key configured.
- Tests: none yet.
