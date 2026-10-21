# 0001 — PHP 8.3 without a framework

- **Status:** Accepted (amended 2026-10-21: Guzzle 8, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The app is small: a handful of JSON endpoints, a state-machine bot, metrics and reports. It must
run both in Docker and as a plain local install with `php -S`, and stay easy for one person to read
end to end.

## Decision

PHP 8.3 with no framework. A single entry point (`public/index.php`) and a tiny router. Composer
dependencies are limited to `guzzlehttp/guzzle` (upstream HTTP; version per the amendment),
`vlucas/phpdotenv` (`.env`), `symfony/yaml` (flow definitions), and `phpunit/phpunit` (tests) as
require-dev only. Required
extensions: `pdo_sqlite`, `curl`, `mbstring`, `openssl`; `apcu` optional from Phase 3 (ADR 0014).

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
  soon. P1-10 checks for 8.3.x patch releases, and so does every later release.
- Moving to another major version, or dropping Guzzle, needs a new ADR.
