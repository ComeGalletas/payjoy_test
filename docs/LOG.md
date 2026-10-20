# Log

Dated progress and anomalies: quota usage, upstream errors, test failures, tool versions. Newest
entry first. Dates are `YYYY-MM-DD`, America/Bogota. The owner mirrors anything relevant into the
"Payment conversion app architecture" chat.

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
