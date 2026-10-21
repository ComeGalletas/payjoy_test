# Log

Dated progress and anomalies: quota usage, upstream errors, test failures, tool versions. Newest
entry first. Dates are `YYYY-MM-DD`, America/Bogota. The owner mirrors anything relevant into the
"Payment conversion app architecture" chat.

## 2026-10-20 — P0-05 and P0-06 close-out review (PR #4)

- PR #4 (P0-06 close-out) was merged by the owner as `b823ab8` before its tester and judge
  finished; at the owner's request their results were posted on the PR afterwards. The merged head
  `4381593` is the reviewed head. Tester: PASS 8/8. Judge: APPROVE WITH NITS (fixed in this PR:
  status wording, GUARDRAILS rule wording, `auto` cost, LOG approval and timing details, TODO L-04
  line).
- P0-05: the owner added `EXCHANGERATE_API_KEY` to `.env`. `ADMIN_TOKEN` isn't needed yet; the owner
  will set any random value. Until it is set, admin routes refuse every request (fail closed,
  P1-01). Agents did not read `.env`.

## 2026-10-20 — P0-06 guard hardening (PR #3, head 912c7c2)

- PR #2 (P0-04 close-out) merged by the owner as `0ac5569`. The owner approved the two `CLAUDE.md`
  host-quirk lines in chat: "I approve PR #2 including the two CLAUDE.md host-quirk lines".
- P0-06 approved before work started: the lead proposed it in PR #2; owner: "start P0-06 once I
  confirm the merge".
- Builder: DONE_WITH_CONCERNS, one commit `912c7c2`. Guard tests 53 → 80 (1244 assertions on
  Windows, 1242 on Linux CI); `make check` took 25.3–27.7 s on Windows, the guards 1.3 s on Linux
  CI. Pinned
  `actions/checkout@fbc6f39… # v5.1.0`, `actions/cache@0057852… # v4.3.0`, and kept
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
