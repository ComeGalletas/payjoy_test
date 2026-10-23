# 0005 — Local install primary through Phase 2; Docker compose from Phase 3

- **Status:** Accepted (amended 2026-10-20 after the pre-work review; note 2026-10-22: the image
  pins a fixed PHP, ADR 0001 third amendment)
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The full stack (API, Typebot builder and viewer, Postgres, Ollama) is easiest to run with Docker,
but the MVP must also run on a machine without Docker. The first version of this ADR called Docker
"primary" and required both install paths to be tested before each phase, yet the plan has no
Docker task before P3-01.

## Decision

- **Phases 1–2:** the local install is the primary and only tested path: PHP 8.3 with
  `pdo_sqlite`, `curl`, `mbstring`, `openssl`, Composer, and `php -S`, documented in
  `docs/INSTALL_LOCAL.md`.
- **Phase 3 onward:** `docker compose` becomes the primary deployment, with the PHP app as the
  `api` service and profiles `typebot` and `ollama`. The `api` image is `php:8.3-fpm-alpine` plus
  nginx, pinned to a patch release of 8.3.36 or later (ADR 0001 third amendment). SQLite lives on
  a named volume, not a Windows bind mount. The local install stays supported and tested.

## Consequences

- Nothing in Phases 1–2 may need Docker.
- APCu is optional and only arrives in Phase 3; the SQLite cache must work without it.
- From Phase 3, both install paths are tested before the phase is delivered.
