# 0004 — Typebot as optional Phase 3 front-end

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

A visual builder lets non-developers change the conversation, but the core logic and metrics must
not depend on it.

## Decision

Typebot is an optional, self-hosted Phase 3 front-end (Docker profile `typebot`). It calls the same
catalog endpoints as every other channel, uses `{{resultId}}` as `session_id` and
`channel=typebot`, and shows money only from the `display` block. `config.bot.frontend` selects
Typebot or the static widget; `/health` checks the Typebot viewer so the loader can fall back to
the static widget automatically.

## Consequences

- The static flow stays maintained as the fallback.
- A contract test checks every HTTP block in the Typebot export against the capability catalog.
- No business logic lives in Typebot.
