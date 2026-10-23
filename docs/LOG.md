# Log

Dated progress and anomalies: quota usage, upstream errors, test failures, tool versions. Newest
entry first. Dates are `YYYY-MM-DD`, America/Bogota. The owner mirrors anything relevant into the
"Payment conversion app architecture" chat.

## 2026-10-22 — L-04 cleanup A (PR #33, head cd9a648)

- PR #32 (the PHP2Shell and cleanup-scope docs) merged by the owner as `4cb1921`.
- **Build.** The builder started in parallel with #32 (the recorded Deviation 3 departure), got
  the review's scope corrections the same day, and merged `origin/main` once #32 had merged (head
  `cd9a648`). Builder: DONE_WITH_CONCERNS (the `usage:` lines, its stricter in-flight rule, and
  `^8.3.36` letting older 8.4 and 8.5 releases through). Unit+Acceptance went from 1956 to 2057
  tests; guards 80. All cleanup A items are in (PLAN L-04 history in git): a missing SQLite file
  refused everywhere but plain migrate, the schema check before WAL, `amount.max` below 1 refused
  at load, parsed-address comparison, the unique-violation retry, the `Metrics` bool, the in-flight
  expiry answer, one clock reading, the `seed_demo` fixes, the hints, the `CsvWriter` guard, and
  `require.php` `^8.3.36` with `SmokeTest` reading the pin.
- **Review.** Tester: PASS WITH NITS, 19 of 22 mutations caught (the survivors: the no-create
  open flag untested, the `expires_at` equality untested on its own, and one equivalent). Judge:
  APPROVE WITH NITS; the code follows the merged ADR 0001 third amendment and ADR 0021 second
  amendment (the owner's condition), and the builder's stricter in-flight rule is redundant but
  safe.
- **Owner decisions** (2026-10-22): merge as is; three items go to cleanup B: `require.php`
  `~8.3.36`, 8.3 only (ADR 0001 fourth amendment); unmap IPv4-mapped addresses before comparing
  (ADR 0016 fifth amendment); a non-integer `amount.max` refused at load. The review nits go to
  cleanup B too.
- Co-leads: the owner and the lead approved (ADR 0015); the lead posted the approvals comment
  (02:23:17 UTC on 2026-10-23) and merged at the reviewed head as `f95dafe` (02:23:20 UTC). The
  lead then ran `composer install` in the main checkout: `platform_check.php` requires 80336.
- **Process.** No guard blocks in the build or its review.
- Lead docs: ADR 0001 fourth amendment (8.3 only), ADR 0016 fifth amendment (one clock reading,
  parsed addresses, the IPv4-mapped decision), ADR 0019 second amendment (a missing database
  file), ADR 0021 second amendment as built (the row rule, the `liveSession()` close, a known
  limit), ADR 0024 amendment (the seed fixes); INSTALL (`DB_DSN`, migrate's prefixes, a missing
  file, `amount.max`, `/health`), REPORTS (the hint and cleanup B's note wording), CONTEXT
  (unhealthy, client IP), PLAN L-04 (cleanup A done, cleanup B's list, the backlog trimmed),
  TODO.
- Review of this docs PR (#34): tester FAIL (INSTALL said every command fails on an `amount.max`
  below 1; nits on credits and wording), judge REQUEST CHANGES (ADR 0001 promised a runtime
  refusal of 8.4 that Composer's lower-bound platform check doesn't give; INSTALL still said
  "8.3.36 or later"; the `~8.3.36` PLAN item lacked the lock refresh and the exact check; ADR
  0021 credited the builder's row rule to the owner); all findings applied in round 2, except
  ADR 0010, left as is (its `api/var/` line is still the default). The tester hit one guard block
  on an optional inline `php -r` probe, reported it and dropped the probe (GR-030).

## 2026-10-22 — PHP2Shell review and L-04 triage

- **CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell").** At the owner's request the lead reviewed
  them against this project. PHP published both on 2026-10-21 (GHSA-w8hw-w9fx-j79w,
  GHSA-6wmf-4rv5-p3rp); news of them leaked on 2026-10-20 (Huntress), and after publication public
  proofs of concept and exploitation attempts were reported (Rapid7, watchTowr). CVE-2026-109618:
  PHP's out-of-memory exit can leave request memory invalid (a crafted multipart body or any input
  that exhausts `memory_limit`; on mod_php before any application code runs). CVE-2026-109619: a
  use-after-free across requests on one worker (memory disclosure also reported). Both CVSS 4.0 9.2;
  remote code execution confirmed on mod_php, PHP-FPM affected too. Fixed in 8.3.36 (and 8.2.35,
  8.4.27, 8.5.12); the upgrade is the only complete fix.
- **Exposure.** The dev host ran 8.3.33 (vulnerable). CI was already on 8.3.36 (`setup-php`
  resolves `8.3` to the latest patch). Nothing is deployed, and `php -S` binds `127.0.0.1`, so
  the exposure was low; but the app keeps the API key and admin token in process memory once it
  has read `.env`, so a memory leak would matter. The app takes no uploads and no PHP sessions,
  but the known triggers are in PHP's request handling, before the app runs.
- **Upgrade.** The owner ran `winget upgrade --id PHP.PHP.8.3`. The first attempt failed ("Access
  is denied" on `brotlicommon.dll`) because a `php -S` the owner had started from the Terminal
  panel still held the DLL; after stopping it the upgrade went through. `php -v`: 8.3.36;
  `curl.cainfo` and `openssl.cafile` kept their values; `make check` passed (1956 tests, 80
  guards). The dev host's `php.ini` still has the PHP defaults `file_uploads=1` and
  `session.upload_progress.enabled=1` (INSTALL section 1 now says to turn them off on the
  server) and `display_errors=1` and `expose_php=1` (already in INSTALL); it is the owner's
  machine configuration.
- **Decisions** (owner): PHP 8.3.36 at least, with `require.php` `^8.3.36`, the platform pin,
  the lock refresh and `SmokeTest`'s version check in cleanup A; the `php.ini` hardening in
  INSTALL; the P3-01 image notes (ADR 0001 third amendment). A message in flight when the expiry
  sweep closes its session gets a new session and the greeting (ADR 0021 second amendment).
- **L-04 triage.** The lead sorted the 29 backlog items and the open owner notes. The owner
  scheduled cleanup A (correctness and safety) and then cleanup B (tests, comments and small
  tidy-ups), each with a builder, tester and judge; the lists are in PLAN L-04. Owner-owned items
  went to issue #31. Left in the backlog, with the reason:
  - the `ReportQuery` split, the `STEPS` derivation and `SessionSweeper::sweepAll()` (with the
    P3 rollups); `report.php`'s timezone (once config is editable, P3-02);
  - the `AppState` helper and the `IpBlocks` retry test (L-03 Postgres);
  - widget ETags (P3-01 nginx; the rate limits on `/reports` and `/health` and `HEAD` are already
    P3-01 items);
  - the `IpBlocking`/`Retention` and `FlowDefinition`/`Step` refactors, `abandon()`'s nine
    parameters and `read()`'s 4-tuple (no bug behind them);
  - the `AmountParser` sweep note (a review rule for the pinned `SWEEP_*_READ` counts, not code
    to change) and naming the line in the flow loader's `!!` error (a small nicety; with the
    `FlowDefinition` refactor);
  - documenting the busy wait before a skipped sweep: already in ADR 0021's known limits, so it is
    dropped from L-04;
  - two notes with no action: `ConvertService` and `display()` both compute the exact total
    (harmless), and `RoundingRuleTest` can't see dynamic calls (the behaviour tests are the
    backstop);
  - logging the `AllowedOrigins` warning once (needs stored state); NFC in `TextNormalizer`
    (needs `intl`); the "Just one payment" text (only if `installments.default` changes);
  - the INSTALL section 8 indent (needs a docs and test change together);
  - the live-code recapture (a release step) and the untried prune-task forms (manual checks);
  - the owner's calls: the withdrawn currencies, `Env::get` precedence, HMAC token comparison.
- **Process: a recorded departure from CLAUDE.md Deviation 3.** The cleanup A builder started
  before this docs PR (#32) merged, which carries the ADR decisions it depends on. The owner
  approved it with conditions (2026-10-22): the cleanup A PR merges only after #32; the builder
  runs `git merge --no-edit origin/main` once #32 is merged; and the cleanup A judge checks the
  code against the merged ADR 0001 third amendment and ADR 0021 second amendment. The builder got
  the review's scope corrections the same day. This is the second departure after P1-09's; issue
  #31 proposes rewording Deviation 3 to "merged before the builder's PR merges".
- Review of this docs PR (#32): tester PASS WITH NITS (it ran `make init` with a chained `cd`
  inside its own worktree, against the one-command rule), judge REQUEST CHANGES (no ADR 0021
  amendment for the in-flight change; security wording beyond the advisories; three backlog
  items without a reason; the parallel start not recorded); all findings applied in round 2.

## 2026-10-22 — P1-10 End-to-end run and live codes (MVP delivered; PR #28, head fbe4d59)

- PR #27 (P1-09 close-out) merged by the owner as `e761e7e`, the build this run used.
- **Owner decisions.** The lead runs the real-key steps: a one-time exception, spent in this run,
  to GR-002 and GR-030's guarded-target clause as they apply to an app process an agent starts
  (the app reads `.env`; the lead never does) and to the practice that agents never call the real
  upstream. The budget: one `/latest/USD` call and two `/quota` calls. The owner registers the
  prune task from a lead runbook. The code follow-ups become their own builder task (PR #28).
- **The run** (main checkout, `api/var/app.db` new: 0 events, 0 demo rows, 0 sessions, 0 rates;
  the server by INSTALL section 7's real-use command in a shell without `DB_DSN`; `curl.cainfo`
  and `openssl.cafile` set): `GET /health` 200 with no `X-Powered-By`. The CLI bot completed
  (200 USD, BRL, 3: "about 1,004.92 BRL today (rate 5.0246, as of 2026-10-22 00:00 UTC)"), the
  run's one `/latest/USD` call at 18:43:21Z. In the built-in browser the widget opened lazily; a
  typed `<script>` and `<img onerror>` showed as text, created no element and ran nothing; then
  the session completed from the stored rates. The lead's `php api/bin/report.php` read showed
  one completed session per channel and the `latest` row 1 call.
- **Acceptance.** At the end of the run the owner viewed `/reports` (admin token, CSV, about
  20:39Z): static 2 started and 2 completed (the widget and the `<script>` embed), cli 1 and 1,
  `latest` 1 call, `quota` 2 calls. **Acceptance met:** one completed session per channel, and
  quota used 3 calls (1 `/latest/USD`, 2 `/quota`).
- **Quota.** Two reads through the app's `QuotaCache` in-process (the admin view's path; the lead
  holds no admin token): 1,500 remaining at 18:44:21Z and 1,498 at 19:46:45Z, no refresh in
  between. Assuming no other client used the key, upstream counts `/quota` calls (at least one of
  the two), and its count lagged by more than a minute.
- **Embeds** (owner-authorized, a one-time exception to the lead's docs-only edit scope, CLAUDE.md
  Roles): `cors.allowed_origins` set to `http://127.0.0.1:8081` in
  `api/src/Config/defaults.php`, uncommitted, then reverted with
  `git checkout -- api/src/Config/defaults.php` (status clean, `frame-ancestors 'self'` again).
  From a host page served on :8081: the iframe loaded `/bot/widget` and its own script and style
  sheet with no console errors; the `<script>` embed loaded its style sheet from the API origin and
  completed a session over CORS (50 USD to 158,677.24 COP, from the stored rates, no extra call).
- **By-hand INSTALL pass.** Everything tried worked as written. The backup line on the real
  database wrote `api/var/app.db.bak-20261022-184457`; a restore into a scratch copy passed
  `integrity_check` with matching counts and a current schema. The owner registered, ran and
  removed the prune task in PowerShell (`LastTaskResult` 0, run from `C:\WINDOWS\System32`). The
  `git clone` URL matches. Untried: the S4U form, `schtasks --%`, cron, and the `sqlite3` lines
  (not installed).
- **Live codes** (from `rate_cache`, 166): ANG, CNH, HRK, SLL and ZWL were missing from
  `FallbackCurrencies`; KPW, SVC and VED were not quoted; CNH and HRK had no `MinorUnits` exponent.
  `composer outdated`, transitive dependencies included: everything up to date.
- **PR #28** (P1-10b). Round 1 (`220db29`): builder DONE; 1935 to 1945 tests. Tester: PASS WITH
  NITS, 11 of 13 mutations caught; judge: APPROVE WITH NITS (the docblock got the KPW/SVC/VED
  sequence wrong; nothing pinned the kept codes; BGN classified unlike HRK). Owner: the builder's
  four decisions accepted, a short round 2, BGN to the legacy group. Round 2 (`fbe4d59`): 1956
  tests, guards 80. Tester: PASS, 8 of 8; judge: APPROVE WITH NITS. The owner and the lead
  approved (ADR 0015); the lead posted the approvals comment (19:49:33 UTC) and merged as
  `38ceb76` (19:49:36 UTC).
- **Process.** The round-2 builder hit a harness block on a `php -r` with `$` expansion ("…
  evaluates … inside a construct too complex to verify … Split it into plain, separate
  commands"). It did not retry; it took the hash from its own acceptance test's failure output,
  and the lead recomputed it independently. The judge ruled it fair use, and the owner ratified
  it as a precedent (co-lead session, 2026-10-22): after a block on a command's form, not its
  target, the same value may be read once from the project's own test or gate commands, never
  through another shell, script or alias, never reaching a guarded target, and the block is still
  reported. `CLAUDE.md` GR-030 and the GR-030 row of `docs/GUARDRAILS.md` (both owner-owned)
  don't say this yet; until the owner writes it there, the lead's briefs carry it. Proposed in
  issue #30 (GR-005). No other block in P1-10.
- **Upstream: 3 real calls** (1 `/latest/USD`, 2 `/quota`), all by the lead under the owner's
  authorization; no agent read `.env`, and no key or token was printed.
- Lead docs: ADR 0012 third amendment (the live code list, the cost of `/quota`); ADR 0011 third
  amendment (CNH, BGN, HRK); ADR 0001 note (the patch check); CONTEXT (quota, fallback currency
  list); INSTALL and REPORTS (`/quota` counts); PLAN P1-10 done note and L-04; TODO (P1-10 ticked,
  next P2-01).
- Review of this docs PR (#29): tester PASS WITH NITS (it ran four commands as
  `cd <its worktree> && …`, against the one-command rule, inside its own worktree only), judge
  REQUEST CHANGES (the one-time exception wasn't scoped to P1-10, and the acceptance names the
  owner viewing `/reports`); the owner then viewed `/reports`, and all findings were applied in
  round 2.

## 2026-10-22 — P1-09 Seed data and docs (PR #26, head b3ae82d)

- PR #24 (P1-08 close-out) merged by the owner as `fdb851d`.
- Owner decisions before the build: CLAUDE.md says builders don't edit `docs/`, so for P1-09 only
  the builder may create exactly `docs/README.md`, `docs/INSTALL_LOCAL.md` and `docs/REPORTS.md`;
  and `seed_demo` writes no rates (the INSTALL `/convert` uses the real key, the test the mock).
- **Round 1.** A Claude Code harness hook refused the builder's Write of `docs/REPORTS.md`
  ("Subagents should return findings as text, not write report files"); the other two docs were
  written. The builder applied the stated remedy once (the full file in its report) and stopped
  (GR-030). The lead did not write into its worktree; by owner decision the lead added the file
  in lead PR #25, merged by the owner as `f8e5f3f`, and the builder merged main and opened
  PR #26 (`d860a31`). Builder: DONE_WITH_CONCERNS in every round (the concerns were its
  follow-ups for the lead). Unit+Acceptance went from 1918 to 1925 tests; guards 80. Tester:
  PASS WITH NITS, 12 of 18 mutations caught. Judge: REQUEST CHANGES, no must-fix; should-fix: the
  `schtasks` line fails in PowerShell, the backup advice (WAL, backups keep raw IPs), the
  `php -S` bind advice was backwards, the guide seeded the real database (P1-10 would pass on
  demo rows), the demo-marker wording, editing `defaults.php` against `git pull`, and four test
  gaps (INSTALL's output blocks, a failed-write rollback, the write subcommands on a corrupt
  schema, `rate_as_of` and the template).
- Owner decisions: round 2 with those seven items only (the nits to this close-out); all six seed
  decisions accepted; the derived demo session ids kept.
- **Round 2** (`e0b2e04`): `Register-ScheduledTask` and `schtasks --%`, a `VACUUM INTO` backup,
  the bind advice, a throwaway `DB_DSN` for the demo, the marker wording, the config failure
  table, and tests that compare INSTALL's output blocks with real output; 1932 tests. Tester:
  PASS WITH NITS. Judge: REQUEST CHANGES; must-fix R2-1: a shell `DB_DSN` doesn't beat `.env`
  under `php -S`. The lead confirmed it with a fixture router under `php -S` (scratchpad only,
  `APP_ENV=test`, gate flags, no key): with `variables_order=GPCS` the variable is in `getenv()`
  only, with `EGPCS` also in `$_ENV`.
- Owner decision: the docs-only fix (the demo server with `-d variables_order=EGPCS`), plus
  explicit report dates and a test that every demo command uses the throwaway database (the
  tester's A and E, part of the option the owner chose).
- **Round 3** (`b3ae82d`): those three; 1935 tests, guards 80. Tester: PASS WITH NITS, 7 of 8
  mutations caught. Judge: APPROVE WITH NITS.
- Co-leads: both approved. The lead posted the approval comment (18:07:19 UTC), then merged at the
  reviewed head as `84e61a2` (18:07:23 UTC).
- **Process.** Besides the harness hook above, one guard block: the round-2 tester's
  `env -C <dir> php -r …` was refused ("cannot be shown not to be git"); its stated remedy had
  already been done, so the tester did not retry (GR-030 followed). No other block (the builder's
  and testers' reports); the builder kept the one-plain-command rule in every round. The owner's
  "no rates" decision came before the build but gets its ADR (0024) only now; CLAUDE.md
  Deviation 3 asks for such an ADR before the builder starts.
- Upstream: 0 real calls.
- **For the owner (owner-owned files unchanged).** P1-09 adds `api/db` to the INV-01 gap noted
  2026-10-21: `RoundingRuleTest` scans `api/src` only, so `api/bin`, `api/db` and `api/public`
  aren't covered (the P1-09 test has its own token scan for `seed_demo`; widening is in L-04).
  Proposed wording for the enforcing layer: "`RoundingRuleTest` (a token scan of `api/src/`, in
  `make check`) fails on `round(`, `number_format(`, `floatval(`, `(float)` and printf float
  formats outside those two files; the widget scan in `P1_07_WidgetTest` covers `toFixed`,
  `Intl.NumberFormat` and `Math.round`". CLAUDE.md's "Builders don't edit `docs/`" had a
  one-task exception here; if it should become a rule for doc tasks, that is a CLAUDE.md change
  (GR-005, an issue).
- Lead docs: ADR 0024 (demo seed data); ADR 0016 note (`rate_limits` holds IPs); PLAN §2, §3, the
  P1-09 done note and follow-ups in P1-10 and L-04; CONTEXT "demo data"; `REPORTS.md` example
  dates; INSTALL nits from the PR #26 reviews (judge round 1: 8 `DB_DSN`, 10 "current or ahead",
  11 the repair without `sqlite3`, 12 cron, 13 the test-suite sentence, 14 the curl CA check;
  round 2: R2-2/R2-7 backup names and the SQLite version, R2-3 the backup time zone, R2-4
  `-Force`, R2-5 config failures; round 3: R3-1 and R3-2 the server wording; tester round 2: C,
  F); the rest are in L-04 with their ids.
- Review of this docs PR (#27): tester PASS WITH NITS, judge REQUEST CHANGES (the repair had a
  PHP form only for the update, not for the schema checks); all findings applied in round 2
  (`051cb46`); the judge's re-check confirmed them (APPROVE WITH NITS, six nits applied: the
  update now comes after the checks in its own block, the demo block's rate-limited minutes,
  tester G and the reclassified [16] in L-04, `EGPCS` without a `php.ini`).

## 2026-10-22 — P1-08 Reports and health (PR #23, head 2e9b293)

- PR #22 (P1-07 close-out) merged by the owner as `24ac2b9`.
- **Round 1** (`3617926`). Builder: DONE_WITH_CONCERNS, for its seven open decisions (its report
  to the lead; the PR body, rewritten after round 3, says DONE). It built
  `ReportQuery`, `ReportPeriod`, `ReportFormatter`, `CsvWriter`, `GET /reports`, `bin/report.php`,
  `GET /health` and `QuotaCache`, and stored the rate per conversion. Unit+Acceptance went from
  1772 to 1888 tests; guards stayed at 80. Tester: PASS WITH NITS, 21 of 24 mutations caught;
  should-fix: the report sweeps at most 500 expired sessions, `9999-12-31` crashes into a 500,
  labels copied from events can show a seeded IP or key, a `;` opens a formula in a
  semicolon-locale spreadsheet, a digit-only step crashes the table, and cents sums can overflow
  to a float. Judge: APPROVE WITH NITS; should-fix: the funnel's step rows don't
  add up.
- Owner decisions: all seven accepted as built (`GET /health` before the database opens, with 503
  `UNHEALTHY`; both quota dates; `meta.endpoint=quota`; the rate stored per conversion; the cohort
  funnel with `never_answered`; `from`/`to` with `BAD_REQUEST`; CLI exit codes 0/1/2), no rate
  limit on `/reports` and `/health` until P3-01, and the full round 2.
- **Round 2** (`8531ef5`): a bounded sweep, a year cap of 9998, allow-lists on every label, a
  semicolon-safe CSV writer, string step labels, a cents range check with `unreadable_amounts`,
  funnel rows that add up, `GET`-only and Bearer-only `/health`, and no `/quota` call while the
  database can't be written; 1913 tests. Tester: PASS WITH NITS, 17 of 18 mutations caught. Judge:
  APPROVE WITH NITS; should-fix: the step allow-list was narrower than the flow loader's pattern.
- Owner decisions: a short round 3, and the funnel field `answered` renamed `passed`.
- **Round 3** (`2e9b293`): the rename, `FlowDefinition::ID_PATTERN` for steps, a complete `other`
  count, two placement rules (sessions that ended outside the flow order, sessions closed without
  an end event), `not_writable` for the skipped `/quota` lookup, and `ReportQuery::endpoints()`;
  1918 tests, guards 80. Tester: PASS, 6 of 6 mutations caught. Judge: APPROVE.
- Co-leads: both approved. The lead posted the approval comment (15:24:24 UTC), then merged at the
  reviewed head as `5e93139` (15:24:28 UTC).
- **Process.** No guard or hook blocked any agent. The round-1 tester joined its PATH refresh and
  `make init` in one call, a minor break of the "one plain shell command per tool call" rule; no
  other break. The tests' real-socket runs go through `BuiltInServer` (checked by the judge).
- Upstream: 0 real calls (every test uses the mocked handler).
- **GUARDRAILS wording (for the owner; `docs/GUARDRAILS.md` unchanged).** INV-07's parenthetical
  says a database that can't be opened, is behind the code or has a corrupt schema version "gives
  500 on every request by design, ADR 0019". Since P1-08 `GET /health` answers 503 `UNHEALTHY`
  for it instead (ADR 0019 amendment). Proposed wording: "…gives 500 on every request by design,
  except `GET /health`, which answers 503 `UNHEALTHY` (ADR 0019 and its amendment)".
- Lead docs: ADR 0023 (reports); ADR 0019 amendment (`GET /health`); ADR 0012 second amendment
  (the `/quota` cache); ADR 0011 second amendment (the rate per conversion); ADR 0021 amendment
  (completion meta, report sweeps); ADR 0016 fourth amendment (`/reports` and `/health` without a
  rate limit, request kinds); ADR 0022 amendment (the CSV report); PLAN §2, §3, §5, §6, the P1-08
  done note and follow-ups in P1-09, P1-10, P3-01 and L-04; CONTEXT terms.
- Review of this docs PR (#24): tester PASS WITH NITS, judge REQUEST CHANGES (ADR 0019's "500 on
  every request" sentence had no pointer to the amendment; round-2 and amendment attributions;
  the placement and "answer" wording; "allow-list" used in two senses; no P3-01 item for the
  unthrottled routes; the `/quota` cost untracked). All findings applied in round 2 (`587bc30`);
  the judge's re-check confirmed them (APPROVE WITH NITS, four wording nits applied).

## 2026-10-22 — P1-07 Widget (PR #21, head 37fb584)

- PR #20 (P1-06 close-out) merged by the owner as `c99bb15`, and PR #19 (P0-07, deferred) as
  `8816441`.
- **Round 1** (`52a1344`). Builder: DONE. It built the widget in `api/resources/widget/` at
  `GET /bot/widget` with its own JS and CSS routes, a `Response` interface with `TextResponse`, a
  CSP, the INV-01 widget scan, `X-Powered-By` removed and a strict Bearer parse. Unit+Acceptance
  went from 1688 to 1754 tests; guards stayed at 80.
- Tester round 1: FAIL. A browser's `/favicon.ico` request is a 404 that counts toward
  `block.error_responses`, so about 100 page views from one shared IP blocked it for an hour
  (reproduced). Removing the in-flight lock or the empty-reply skip passed the gate. Judge round 1:
  APPROVE WITH NITS: the widget routes have no rate limit, INV-04 had no check for the new response
  type, and the forbidden list missed `Function(`.
- Lead by-hand check (PLAN acceptance), in the built-in browser with `APP_ENV=test`, an empty key,
  a scratch database and the gate flags: a typed `<script>…</script><img src=x onerror=…>` showed
  as text, with no element created and no CSP error.
- Owner decisions: favicon → an SVG icon route plus `/favicon.ico` answering 204; the widget routes
  stay unlimited until P3-01; the tester's widget harness (Node) goes into the suite; lazy open.
- **Round 2** (`5bdcfe2`): the four decisions, the INV-04 route-table test, a wider scan, a CSP with
  Trusted Types, `AllowedOrigins`, accessibility fixes, `BuiltInServer` flags; 1772 tests. The
  session restarted during the build; the builder redid round 2 from a clean worktree (builder's
  report to the lead). Tester:
  PASS (150 page views, no block; Trusted Types enforced; 23 hostile origins handled). Judge:
  APPROVE WITH NITS. Lead's browser check passed again: no request on load, one open on first
  focus, script text shown as text, Trusted Types blocking `innerHTML`.
- Owner decisions: a short round 3 for test and scan hardening; the start button reads "Start
  chat", and the rest of the chrome wording is confirmed.
- **Round 3** (`37fb584`): a 30 s deadline and a 61-check minimum on the harness run, one more
  harness check (`maxLength` 500) and a stricter waiting-message check (the box is emptied), more
  scan patterns (frozen list), "Start chat". 17 of 17 widget mutations caught.
  Tester: PASS; judge: APPROVE.
- Co-leads: both approved. The lead posted the approval comment (13:11:07 UTC), then merged at the
  reviewed head as `1b9b0e7` (13:11:23 UTC). The first merge command was refused because it gave a
  short SHA to `--match-head-commit`; it was rerun with the full SHA.
- **Process.** No guard or hook blocked any agent. The builder broke the "one plain shell command
  per tool call" rule twice in round 1 (a `cd … && …` read and a `cmd /c "cd /d … && make …"` gate
  run), and the tester twice in its first commands (the tester's report to the lead); all were
  read-only or gate runs. Rounds 2 and 3 kept the rule.
- Upstream: 0 real calls (every test uses the mocked handler; the browser checks never reached the
  result step's upstream lookup).
- **GUARDRAILS wording (for the owner; `docs/GUARDRAILS.md` unchanged).** INV-01's enforcing layer
  says "a `make check` grep" for `round(` / `number_format(` and, in the widget, `toFixed`,
  `Intl.NumberFormat` and `Math.round`. It is now `RoundingRuleTest` (PHP in `api/src/`, since
  P1-06) and, for the widget, the widget harness's verbatim-text checks and the scan in
  `P1_07_WidgetTest`; Trusted Types and the harness's DOM proxy enforce output safety (PLAN §3),
  not INV-01.
  INV-04's exceptions now include the widget's script, style sheet and icon and the 204
  `/favicon.ico`, checked by a route-table test. The widget harness runs outside INV-06's network
  layers (ADR 0018 amendment). The GR-002 secret scan is still not built.
- Lead docs: ADR 0022 (widget serving, embedding and non-JSON responses); ADR 0016 third
  amendment (favicon, unthrottled widget routes, other 404 paths); ADR 0018 amendment (test
  server, widget harness); ADR 0021 note (lazy open); PLAN §2, §3, §4, §6, §7, P1-07 done note,
  follow-ups in P1-08, P1-09, P1-10, P3-01, P3-06 and L-04; CONTEXT terms (widget, widget chrome,
  embed, lazy open, widget harness); the ADR index's `note` status form.
- Review of this docs PR (#22): tester PASS WITH NITS, judge REQUEST CHANGES (PLAN §3 had two
  disagreeing "only non-envelope" lists; Trusted Types was labelled an INV-01 control); all
  findings applied in round 2 (`f5f3b0c`); the judge's re-check confirmed them (APPROVE WITH
  NITS, two wording nits applied).
- For the owner: `CLAUDE.md` lists the lead's paths as `docs/`, `memory/`, `CHANGELOG.md` and
  `.task`, without the root `CONTEXT.md`, which the lead edits in every close-out (judge's note).

## 2026-10-21 — P1-06 Flow engine and `POST /bot/message` (PR #18, head 8887acb)

- PR #17 (P1-05 close-out) merged by the owner as `53199b1`.
- **Round 1** (`f5d0b70`). Builder: DONE_WITH_CONCERNS. It built `flows/default.yaml`, the loader
  (`symfony/yaml` 7.4.21), `FlowEngine`, `SessionStore`, `POST /bot/message`, `bin/bot.php`,
  `InstallmentsReading`, a wider ambiguous list and 13 more countries. Concerns: `symfony/yaml` 7.4
  instead of 8.x, a new `BAD_REQUEST` code, and the §9 vs P1-05 wording conflict.
  Unit+Acceptance went from 1409 to 1665 tests; guards stayed at 80.
- Tester round 1: PASS. 400 random conversations matched `/convert`'s `display` exactly; no typed
  text stored; hostile input never reached a reply. Nits: `!!binary` passed the loader, an alias
  bomb was expanded before the schema check, and a race loser could be stranded. Mutations M6b and
  M9a survived. Judge round 1: APPROVE WITH NITS, with should-fixes on race replay, a sweep that
  needed the YAML and ran without a transaction, and the CLI test's network guard.
- Owner decisions: a double-submit is read again only on the same step, otherwise it gets the
  current question, or `done: true` if the session closed; `not_in_table`
  re-asks the currency; `BAD_REQUEST` accepted, counting toward blocking; per-step retries, the
  first-message rule, `symfony/yaml` 7.4, the `below_minor_unit` wording, `bob` = BOB and `won`
  ambiguous ratified. Every agent brief now carries "one plain shell command per tool call".
- **Round 2** (`8887acb`): the two behaviour changes, `Flow/SessionSweeper` in one transaction, a
  lazily built upstream client (the CLI test runs with ADR 0018's gate flags), loader hardening,
  `RoundingRuleTest`, `bot.max_retries` tests; 1688 tests. Tester: PASS, all 12 mutations caught.
  Judge: APPROVE WITH NITS.
- Co-leads: both approved. **Merge order:** GitHub shows PR #18 merged at the reviewed head as
  `d5c75fd` by ComeGalletas at 03:25:34 UTC (22:25 Bogota, 2026-10-21), 23 seconds before the
  lead's approval comment was posted (03:25:57 UTC); the lead's merge command then reported it
  already merged (its own output). As with PRs #8 and #10, the code is the reviewed code, but the
  order differs from the ADR 0015 amendment (the lead posts the comment, then merges). Raised with
  the owner in chat; no process change decided.
- **Process.** The builder hit two worktree-guard refusals in round 1 and none in round 2 (PR #18
  body); the tester hit three in round 1 and one in round 2, and the judge none (their reports to
  the lead). Each was handled by the block message's own remedy once, or with the Write, Edit or
  Grep tool; no guarded target was touched.
- Upstream: 0 real calls (every test uses the mocked handler; no reviewer reported a real call).
- **GUARDRAILS gap (owner request: note it, leave `docs/GUARDRAILS.md` unchanged for now).**
  `docs/GUARDRAILS.md` lists two checks that weren't built:
  - INV-01's "`make check` grep" for `round(` / `number_format(`, and for `toFixed`,
    `Intl.NumberFormat` and `Math.round` in the widget. Since P1-06, `RoundingRuleTest` (in the
    unit suite, so in `make check`) covers PHP in `api/src/`: `round(`, `number_format(`,
    `floatval(`, `(float)` and printf-family float formats, token-based, outside
    `InstallmentCalculator` and `ReportFormatter`, with five listed `(float)` exceptions
    (`ConfigRepository` 1, `ScaledRate` 1, `ExchangeRateClient` 3). It doesn't cover `floor(`,
    `ceil(`, `fdiv(`, dynamic calls, `api/bin/` or `api/public/` (L-04). The widget checks don't
    exist yet (P1-07).
  - The "secret scan" named as GR-002's backstop and as INV-03's check: not built (task P0-07,
    deferred).
- Lead docs: ADR 0021 (flow engine and bot message contract); ADR 0001 second amendment
  (`symfony/yaml` 7.4); ADR 0004 amendment (Typebot session id and channel); PLAN §2, §3c, §5
  (`BAD_REQUEST`), §6, §9 pointer and the `not_in_table` exception, P1-06 done note, follow-ups in
  P1-07, P1-08, P2-03, P3-05, P3-07, L-03 and L-04; CONTEXT terms (conversion retry, double-submit,
  say step, result template, max-retries reply, session sweeper; code words without `bob`).

## 2026-10-21 — Guard review, transcript audit and P0-07 (deferred)

- **P1-06 builder's guard blocks (PR #18 round 1), checked against its transcript:** 2 refusals,
  both from Claude Code's own worktree isolation ("too complex to verify that it stays inside the
  worktree"), not from a project guard. One Python heredoc edit was redone with the Write tool; one
  compound status check was redone as the stated remedy once, plus Grep. Of its 172 tool calls, 0
  reached a path outside its worktree, 0 named `.env`, and 0 used a hook-bypass or destructive
  flag. Handled within the GR-030 exceptions.
- Owner decisions: leave the guards as they are for now; every agent brief now says "one plain
  shell command per tool call" (no chains, loops, pipes into scripts or heredocs; Write/Edit for
  files, Grep/Glob for searches). The lead's count: every builder so far had hit about two such
  refusals, mostly on compound commands, heredocs or `.git` paths.
- **Transcript audit** (lead, all 57 transcripts on this machine: the lead's session and 56
  subagents; text matching on tool inputs, plus a search of tool outputs for key or token values):
  - `.env`: no Read, Edit, Write, Grep or Glob call on it. 15 shell commands name it, all for its
    name only: existence and ignore checks, the `.gitignore` rules, commit and PR text, a source
    search, the lead's scans. No real `EXCHANGERATE_API_KEY` or `ADMIN_TOKEN` value appears in any
    output; the 8 matches are `.env.example`'s empty placeholders. The app's scripts never ran
    against the main checkout's `.env`; two `migrate.php --dump` runs were in a worktree and a
    scratch copy, where no `.env` exists.
  - Owner files: `CLAUDE.md` (6 commits) and `docs/GUARDRAILS.md` (12) were edited only in the
    initial import (`9ec7f2f`, before PRs existed) and in docs PRs the owner asked for or approved.
    Builders edited guard files in their own worktrees, in owner-assigned tasks (P0-04, P0-06) or
    with ADR 0018's approval (P1-04), with one exception: in P0-04 round 1, `make init` run from
    the worktree installed the branch's hooks into the main checkout's shared `.git/hooks`
    (finding A, LOG 2026-10-20), which the owner accepted on record. The P1-01 gate-script
    change was ratified by the owner afterwards (PR #7). No agent edited `.claude/settings*`,
    `AGENTS.md` or a data root. The lead read the owner's user settings file once, read-only,
    during the guard review.
  - Refusals: 35 worktree-complexity refusals across 14 subagents, plus one permission-classifier
    denial in P0-06 (`git restore --source=origin/main`, LOG 2026-10-20); none involved `.env`.
    Two builders switched shells after a refusal, before the GR-030 exceptions: P1-01's switch
    reached no guarded target; P0-04's refusals concerned `.git` paths, and its PowerShell re-run
    includes the hook install above (finding A) and reads of git metadata.
  - Limits: text matching misses indirect access (the output search is the backstop); the audit
    covers Claude sessions on this machine only; and the lead audited its own session too.
  - Correction: in chat the lead first told the owner that neither shell switch reached a guarded
    target; the PR #19 review found the P0-04 case above, and this entry is the corrected record.
- **P0-07 added and deferred** (owner decision): a Claude Code guard layer, `.env` deny rules first,
  then a PreToolUse guard. Feasibility was checked against the Claude Code docs (version 2.1.303).
  The owner is reviewing the settings template; nothing is applied.

## 2026-10-21 — P1-05 Convert and `GET /convert` (PR #16, head 0eda0ce)

- PR #15 (P1-11 close-out) merged by the owner as `f751c91`. Owner decision: GUARDRAILS INV-08 now
  reads "every request that reaches the Kernel writes one `requests` row, unless the write fails
  (INV-07)"; edited in this close-out with the owner's approval (GR-006).
- **Round 1** (`d896555`). Builder: DONE_WITH_CONCERNS. It built `MinorUnits`,
  `InstallmentCalculator::display()` per §3a, `ConvertService`, `GET /convert` behind the global
  rate limiter, one `api.request` event per answered request, and the 503 during an
  `unsupported-code` backoff (`not_in_table` stays 400). Unit+Acceptance went from 1295 to 1405
  tests; guards stayed at 80. Concerns: float drift on large totals, and new upstream codes
  rejected on a cold start.
- Tester round 1: PASS with concerns. An independent bcmath oracle over 2.3M cases found
  `total_m` off by one minor unit on large totals (IRR 3.3%, LBP 0.5%, IDR 0.24%, COP 0.05%),
  mostly from PHP 8.3's `round()`. It also found a P1-04 bug: `UpstreamBreaker::write()` swallowed
  a failed INSERT. Judge round 1: APPROVE WITH NITS; it recommended an exact integer total and a
  narrower status override.
- Owner decisions: compute the total exactly; group `display.amount_usd`; keep `display.rate`
  grouped; fix the breaker-write bug in round 2.
- **Round 2** (`0eda0ce`): exact `total_m` from cents and `rate_scaled` in int64 limbs, the status
  rule in `ErrorCodes`, the breaker fix, test and comment nits; 1409 tests. Tester: PASS, 0
  mismatches in 2.5M oracle cases and 3M limb cases, all 13 mutations caught. Judge: APPROVE WITH
  NITS (0 mismatches in 600,728 cases).
- Co-leads: both approved. The lead posted the approval comment, then merged at the reviewed head
  as `c09edba` (2026-10-22 01:16:31 UTC).
- **Process.** The builder hit two worktree-guard refusals in round 1 (PR #16 body), and the tester
  two in round 1 (its report to the lead). In each pair one command was dropped as unneeded; the
  other was handled by the block message's own remedy ("split it into plain, separate commands")
  once, plus the Write or Grep tool. No guarded target was touched.
- Upstream: 0 real calls (every test uses the mocked handler; no reviewer reported a real call).
- Lead docs: ADR 0011 amendment (exact total, grouped strings, reasons, `MinorUnits` choices);
  ADR 0017 second amendment (`toRate()`); ADR 0012 item 9 implemented and the breaker-write limit;
  GUARDRAILS INV-08; PLAN §1, §3a, §6, P1-05 done note, follow-ups in P1-06, P1-08, P1-10, L-03
  and L-04; CONTEXT terms (unsupported-code backoff, api.request event, the two reasons).

## 2026-10-21 — P1-11 Request log and IP blocking (PR #14, head 6ce7df5)

- PR #13 (P1-04 close-out) merged by the owner as `b963187`.
- Owner decision before P1-05: while an `unsupported-code` backoff is open, lookups answer 503,
  not 400 (ADR 0012 amendment; P1-05 applies it).
- **Round 1** (`f527792`). Builder: DONE_WITH_CONCERNS. It built the request log
  (`Metrics/RequestLog`), the block check before routing, automatic blocking (`Http/IpBlocking`),
  block rows per the ADR 0016 amendment (`Http/IpBlocks`) and `bin/ip.php`, and listed 12
  decisions the ADR left open. No schema change. Unit+Acceptance went from 1123 to 1243 tests;
  guards stayed at 80.
- Tester round 1: FAIL on two minor bugs. A path cut by bytes could end in half a UTF-8
  character, and `unblock` then `--prune` lost the clean slate. Dropping the transaction in
  `IpBlocks` went uncaught. Judge round 1: APPROVE WITH NITS, but one 35-request burst in a minute
  blocked an IP, and everyone behind a carrier NAT, for an hour.
- Owner decisions: 429s count as distinct rate-limited minutes; prune keeps an ended block one
  window longer; `ip.block` and `ip.unblock` events that never hold the IP; a friendlier 403 text.
  Fail-open and the exemptions were ratified as built. Co-lead addition: 429s no longer count
  toward `block.error_responses`.
- **Round 2** (`47dbb66`): the four decisions, percent-encoded path and method, a transaction
  test; 1282 tests. Tester: PASS on the code, with one flaky CLI test (`time()` read twice around
  a subprocess). Judge: CHANGES NEEDED, because counting minutes over `requests` made each 429
  scan grow with the flood.
- Owner decision: round 3 before merge.
- **Round 3** (`6ce7df5`): rate-limited minutes read from `rate_limits` (one primary-key range,
  at most `block.window_minutes` rows), each response checks only its own threshold,
  `block.window_minutes` capped at 1440, the flaky test fixed, the cut keeps a character's
  escapes together; 1295 tests. Tester: PASS, all mutations caught. Its report to the lead
  measured about 2 ms per 429 at 10, 5,000 and 50,000 logged rows, and about 4 ms per 404 that led
  to a block with 20,000 rows in the window. Judge: APPROVE WITH NITS.
- Co-leads: both approved. The lead posted the approval comment, then merged at the reviewed head
  as `a3a376b` (22:36:36 UTC).
- **Process.** The builder hit two worktree-guard refusals in round 1 (PR #14 body), and the tester
  one in each of rounds 1 and 3 (its reports to the lead). Each was handled within the GR-030
  exceptions: the stated remedy once in the same tool, or the Edit, Write or Grep tool. No guarded
  target was touched.
- Upstream: 0 real calls.
- Lead docs: ADR 0016 second amendment (what counts toward a block, clean slate, settings, block
  rows and the new prune boundary, exemptions, fail-open, the 403, block events, order, stored
  path and method, `bin/ip.php`, cost and flood consequences, known limits); the 503 decision in
  the ADR 0012 amendment; PLAN §3, §3b, §5 and §6; follow-ups in P1-05, P1-07, P1-08, P1-09,
  P3-01 and L-04; CONTEXT terms (unblock, rate-limited minute, error response for blocking,
  block exemption; automatic and manual blocks).

## 2026-10-21 — P1-04 Rates: client, cache, breaker (PR #12, head cf85902)

- PR #11 (P1-03 close-out) merged by the owner as `8496080`.
- **Round 1** (`cf01ecc`). Builder: DONE_WITH_CONCERNS. It built `ExchangeRateClient` (three fixed
  URLs, 4 s timeouts, 256 KiB body cap, exception scrubbing), rates scanned from the decimal text,
  `RateCache` and `RateStore` with stale fallback, `UpstreamBreaker` (quota, auth and a flat
  5-minute backoff), `FallbackCurrencies`, code words, and `bin/upstream.php`. It also extended the
  no-network guard to curl and Guzzle and added Guzzle 8.3. Unit+Acceptance went from 746 to 1064
  tests; guards stayed at 80.
- Tester round 1: FAIL. Mutation g, building rates from the float instead of the decimal text,
  survived the whole suite. An `unsupported-code` answer from `/latest/USD` never tripped the
  backoff. The key-leak search found 0 hits. Judge round 1: APPROVE WITH NITS. It said a failed
  `rate_cache` write lets traffic burn quota, and a flat 5-minute backoff costs 288 calls a day.
- Owner decisions: round 2 before merge; the backoff grows from 5 minutes to 1 hour.
- **Round 2** (`13fee73`):
  - Every failed refresh trips the breaker. Each of 16 failure kinds costs 1 call, cold and warm.
  - The backoff grows 5, 10, 20, 40, 60 minutes and resets on success.
  - Tests separate the decimal-text route from the float route.
  - Hardening: `decode_content=false` with `Accept-Encoding: identity`; `Rates\ApiKey`; an
    `upstream.breaker_reset` event; `BreakerState` validation; `codes()` equals the keys of
    `table()`; temp databases cleaned up on Windows.
  - 1123 tests, 80 guards. Tester: PASS 12/12, every mutation caught. Judge: APPROVE WITH NITS.
- Owner decision: the backoff starts at 1 minute (1, 5, 10, 20, 40, 60).
- **Round 3** (`cf85902`): the owner asked the lead to make this change directly ("do the change
  yourself"). That was an owner-approved, one-off exception to the lead's edit scope (CLAUDE.md
  Roles) and to the ADR 0015 rerun of tester and judge on a new head; it is not a precedent. Only
  the schedule constant, `backoffSeconds()`, docblocks and tests changed. 1123 tests, 80 guards;
  CI `check` passed (run 39650531350). The owner approved it without a separate tester and judge
  recheck.
- Co-leads: both approved. The lead posted the approval comment, then merged at the reviewed head
  as `7cfe567` (18:13:45 UTC), following the ADR 0015 amendment's order.
- Upstream: 0 real calls. Every test uses a mocked handler.
- Lead docs: ADR 0012 amendment (breaker kinds, backoff schedule, strength order, refresh floor,
  stale age, known limits); ADR 0001 amendment (Guzzle 8.3.0 and its dependencies, 3–4 days old
  when locked); §2, §3b and §5 of PLAN; follow-ups in P1-05, P1-06, P1-08, P1-10 and L-03; new
  CONTEXT terms (backoff, code word, dropped currency, stored table).

## 2026-10-21 — P1-03 Validation and AmountParser (PR #10, head 49b98c1)

- PR #9 (P1-02 close-out) merged by the owner as `61e5774`.
- **Round 1** (`2730787`). Builder: DONE_WITH_CONCERNS; strict `AmountValidator`, bot `AmountParser`
  (§3c, no floats), `CurrencyValidator` with an injected `CurrencyAllowList`, `CurrencyResolver`
  (synonyms, currency-country map, ambiguous list), `InstallmentsValidator`. Unit+Acceptance
  318 → 640, guards 80. Tester: PASS (independent §3c reference over 360k strings, 0 builder bugs).
  Judge: APPROVE WITH NITS, but found three paths where the bot reads a different number:
  `200.505` → 200,505 USD, `200 3` → 2003, `5$5` → 55.
- Owner decisions: a single `.` + 3 digits is unclear in the bot; bot installments accept leading
  zeros; send back for round 2; widen the GR-030 exception to Read, Grep and Glob.
- **Round 2** (`91d0541`): markers only at the ends, inner spaces only as thousands, value-checking
  sweeps; 713 tests. Tester PASS (310k strings), judge APPROVE WITH NITS; both found tab, CR and LF
  still acting as inner thousands separators (`"200\n300"` → 200300).
- Owner decisions: quick round 3; `,` + 3 digits stays thousands (`1,234`, `200,505`).
- **Round 3** (`49b98c1`): only space, NBSP and U+202F inside a number; shared edge-trim set for
  installments; 746 tests (25 in the P1-03 acceptance file), sweep 2 re-pinned at 402,233 inputs.
  Tester PASS (310k strings, 0 builder bugs), judge APPROVE (no remaining wrong-number path). CI
  `check` pass. Co-leads: both approved.
- Merged at the reviewed head as `abb7497` by ComeGalletas at 15:39:26 UTC, about a minute before
  the leads' approval comment was posted (15:40:32 UTC) and before the lead's merge command ran.
- **Process.** Round 1: two worktree-guard blocks. The first was handled by its stated remedy
  once in Bash; for the second, the builder did the read-only check with the Grep tool. That broke
  the letter of the rule at the time (it covered only Write and Edit); it was harmless and
  disclosed, and the owner then widened the rule to Read, Grep and Glob, never against a guarded
  target. Rounds 2 and 3: no blocks.
- Lead docs: ADR 0013 amendment (nine behaviour changes vs §3c), §3c pointer, currency-step
  `clarify` text, the P1-03 acceptance line fixed (`reais`/`R$` record no country), follow-ups in
  P1-04, P1-06 and L-04, CONTEXT terms, and the GR-030 wording in `CLAUDE.md` and
  `docs/GUARDRAILS.md` (owner-ratified exceptions).
- PR #11 review: tester PASS (all nine amendment items true against the code); judge CHANGES
  NEEDED (the amendment's Context overclaimed owner decisions and "only unclear"; fixed). Owner
  decisions: the guarded-target exclusion covers every tool, not only Read/Grep/Glob; builder PRs
  are merged by the lead after the approval comment is posted, never by the owner on GitHub
  (ADR 0015 amendment); the owner still merges the lead's docs PRs.

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
