# 0001 — PHP 8.3 without a framework

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The app is small: a handful of JSON endpoints, a state-machine bot, metrics and reports. It must
run both in Docker and as a plain local install with `php -S`, and stay easy for one person to read
end to end.

## Decision

PHP 8.3 with no framework. A single entry point (`public/index.php`) and a tiny router. Composer
dependencies are limited to `guzzlehttp/guzzle` (upstream HTTP), `vlucas/phpdotenv` (`.env`),
`symfony/yaml` (flow definitions), and `phpunit/phpunit` (tests) as require-dev only. Required
extensions: `pdo_sqlite`, `curl`, `mbstring`, `openssl`; `apcu` optional from Phase 3 (ADR 0014).

## Consequences

- Routing, request parsing, the response envelope and admin-token middleware are written by hand
  in `src/Http/` and need their own tests.
- Few dependencies means few upgrades and a fast local install.
- Adding a dependency needs a new ADR.
