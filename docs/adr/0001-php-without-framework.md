# 0001 — PHP 8.3 without a framework

- **Status:** Accepted (amended 2026-10-21: Guzzle 8, see "Amendment"; amended again 2026-10-21:
  `symfony/yaml` 7.4, see "Second amendment"; note 2026-10-22: the P1-10 patch check, LOG
  2026-10-22; amended a third time 2026-10-22: PHP 8.3.36 at least, see "Third amendment";
  amended a fourth time 2026-10-22: PHP 8.3 only, see "Fourth amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The app is small: a handful of JSON endpoints, a state-machine bot, metrics and reports. It must
run both in Docker and as a plain local install with `php -S`, and stay easy for one person to read
end to end.

## Decision

PHP 8.3 (8.3.36 or later, see "Third amendment") with no framework. A single entry point
(`public/index.php`) and a tiny router. Composer dependencies are limited to `guzzlehttp/guzzle`
(upstream HTTP; version per the amendment), `vlucas/phpdotenv` (`.env`), `symfony/yaml` (flow
definitions; version per the second amendment), and `phpunit/phpunit` (tests) as require-dev
only. Required extensions: `pdo_sqlite`, `curl`, `mbstring`, `openssl`; `apcu` optional from
Phase 3 (ADR 0014).

## Consequences

- Routing, request parsing, the response envelope and admin-token middleware are written by hand
  in `src/Http/` and need their own tests.
- Few dependencies means few upgrades and a fast local install.
- Adding a dependency needs a new ADR.

## Amendment (2026-10-21): Guzzle 8

**Context.** P1-04 (PR #12) added the first Guzzle code. The builder chose the newest stable major,
8.x, which supports PHP 8.3. The reviewers accepted it, and both co-leads approved the PR.

**Decision.**
- `api/composer.json` requires `guzzlehttp/guzzle` `^8.3`. The lock has 8.3.0, which came out on
  2026-10-18.
- Its dependencies came in with it: `guzzlehttp/psr7` 3.2.0 (2026-10-17), `guzzlehttp/promises`
  3.1.0 (2026-10-18), `psr/http-client` 1.0.3, `psr/http-factory` 1.1.0, `psr/http-message` 2.0,
  `symfony/deprecation-contracts` 3.7.1 and `symfony/polyfill-php82` 1.43.0. Dependencies that
  arrive with an approved package don't count as added under "Adding a dependency needs a new
  ADR"; they leave with Guzzle.
- Only `Rates/ExchangeRateClient` uses Guzzle, and the client is injected (ADR 0012). Tests use
  a mocked handler only (ADR 0018).

**Consequences.**
- Guzzle 8.3.0, psr7 3.2.0 and promises 3.1.0 were 3–4 days old when locked, so fixes may follow
  soon. P1-10 checked for 8.3.x patch releases on 2026-10-22 (LOG): none, and a full
  `composer outdated` (transitive dependencies included) was up to date. Every later release
  checks again.
- Moving to another major version, or dropping Guzzle, needs a new ADR.

## Second amendment (2026-10-21): `symfony/yaml` 7.4

**Context.** P1-06 (PR #18) added the flow loader, the first code that uses `symfony/yaml`. The
current major, 8.x, requires PHP 8.4.1 or later; the project is pinned to PHP 8.3.33. The builder
chose 7.4, the long-term-support line, and the owner ratified it.

**Decision.**
- `api/composer.json` requires `symfony/yaml` `^7.4`. The lock has v7.4.21, released 2026-10-12.
- Its requirements (`symfony/deprecation-contracts`, `symfony/polyfill-ctype`) were already
  locked, so it adds one package.
- The loader parses with `PARSE_EXCEPTION_ON_INVALID_TYPE` only (ADR 0021), so PHP objects,
  constants and custom tags are refused.

**Consequences.**
- Moving to 8.x waits for a PHP upgrade to 8.4.1 or later; that needs a new ADR.
- v7.4.21 was nine days old when locked; P1-10 checked for 7.4.x patch releases with Guzzle's
  on 2026-10-22: none.

## Third amendment (2026-10-22): PHP 8.3.36 at least

Recorded from the lead's review of CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"); owner
decisions 2026-10-22.

**Context.** PHP published both on 2026-10-21 (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp); news
of them leaked the day before (Huntress), and after publication public proofs of concept and
exploitation attempts were reported (Rapid7, watchTowr). CVE-2026-109618: PHP's out-of-memory
exit can leave request memory invalid; a crafted multipart body, or any input that exhausts
`memory_limit`, triggers it, and on mod_php that happens during body parsing, before any
application code runs. CVE-2026-109619: a persistent
worker keeps state that points into a previous request's freed memory (a use-after-free; memory
disclosure is also reported). Both are rated CVSS 4.0 9.2 (network, high complexity, no
authentication). Remote code execution was confirmed on mod_php; PHP-FPM is affected too, and the
advisories call the upgrade the only complete fix.

**Decision.**
- The app needs PHP 8.3.36 or later, the 8.3 release that fixes both (8.2.35, 8.4.27 and 8.5.12
  on the other branches).
- `api/composer.json` `require.php` becomes `^8.3.36` (owner decision; `~8.3.36` from the fourth
  amendment), so Composer's generated platform check refuses an older PHP on every entry point;
  `config.platform.php` moves from 8.3.33 to 8.3.36, and the lock's `platform-overrides` with it.
  `SmokeTest`'s version check reads that pin and checks that `require.php`'s lower bound matches it,
  so the two values can't drift (L-04 cleanup A).
- The server's `php.ini` sets `session.upload_progress.enabled = 0` (a confirmed
  CVE-2026-109619 path, which the advisory says to disable even after the upgrade; the app uses
  no PHP sessions), `file_uploads = Off` (the app takes no uploads) and `post_max_size = 64K`
  (its largest body is 4 KiB). The last bounds only a request that declares its length, so
  P3-01's nginx enforces the size. These reduce exposure; they don't replace the upgrade.
  `memory_limit = -1`, the advisory's main configuration mitigation, isn't adopted, because one
  request could then take all the host's memory (`INSTALL_LOCAL.md` section 1).

**Consequences.**
- CI's `setup-php` resolves `8.3` to the latest patch release and was already on 8.3.36; the
  owner upgraded the dev host the same day.
- P3-01's image pins a patch release (for example `php:8.3.36-fpm-alpine`, or by digest) and
  uses PHP-FPM, not mod_php, the server API where both remote code executions were confirmed
  (FPM is still affected, so the pinned release is the fix). nginx rejects multipart bodies,
  caps `client_max_body_size`, and never passes `PHP_VALUE` or `PHP_ADMIN_VALUE`.
- **Known limits.** Between releases nothing detects a new PHP security release; the platform
  check and the gate prove only the floor, and the next release checks again and moves both
  values. On an older PHP, Composer's platform check stops every entry point with a plain error
  (a 500 for HTTP), not the envelope, an intended exception to INV-04. PHP 8.3 security support
  ends on 2027-12-31; moving to 8.4 needs a new ADR (see the second amendment).

## Fourth amendment (2026-10-22): PHP 8.3 only

Owner decision 2026-10-22, from the review of L-04 cleanup A (PR #33).

- `^8.3.36` also accepts PHP 8.4.0 to 8.4.26 and 8.5.0 to 8.5.11, which have the same two CVEs
  (their fixed releases are 8.4.27 and 8.5.12), and both Composer's generated platform check and
  `SmokeTest` check only the lower bound. `require.php` becomes `~8.3.36` (8.3.36 up to, not
  including, 8.4), and `SmokeTest` checks the running PHP against that constraint (L-04 cleanup
  B; the `SmokeTest` check follows the third amendment's rule that the gate and the pin agree).
  Moving to 8.4 or later stays a new ADR (see the second amendment).
- Composer's generated platform check reads only the constraint's lower bound, also for
  `~8.3.36`, and `config.platform.php` makes `composer install` resolve against 8.3.36. So after
  cleanup B only the gate (`SmokeTest`) refuses PHP 8.4 or later; the app itself still starts on
  it. Until cleanup B merges, the gate also passes a PHP 8.4 or 8.5 older than its fixed release.
  CI (`php-version: '8.3'`) and the dev host run 8.3.
