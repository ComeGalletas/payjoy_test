# 0004 — Typebot as optional Phase 3 front-end

- **Status:** Accepted (amended 2026-10-21: session id and channel, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

A visual builder lets non-developers change the conversation, but the core logic and metrics must
not depend on it.

## Decision

Typebot is an optional, self-hosted Phase 3 front-end (Docker profile `typebot`). It calls the same
catalog endpoints as every other channel, uses `{{resultId}}` as `session_id` and
`channel=typebot` (both changed by the amendment), and shows money only from the `display`
block. `config.bot.frontend` selects Typebot or the static widget; `/health` checks the Typebot
viewer so the loader can fall back to the static widget automatically.

## Consequences

- The static flow stays maintained as the fallback.
- A contract test checks every HTTP block in the Typebot export against the capability catalog.
- No business logic lives in Typebot.

## Amendment (2026-10-21): session id and channel

Recorded from P1-06 (ADR 0021); the judge raised it.

- **Session id.** The server issues session ids and treats any id it didn't issue as a new
  session, so Typebot can't use `{{resultId}}`. Typebot sends no id on its first message, stores
  the `session_id` the response returns, and sends it back on every later message.
- **Channel.** `POST /bot/message` doesn't let the body choose a channel; HTTP sessions are
  `static`. P3-05 must add a way to mark Typebot sessions `channel=typebot` (for example a
  per-channel token or route) before Typebot traffic is reported separately.
- All Typebot traffic reaches the API from one host, which matters for `block.error_responses`
  (ADR 0016; P3-01 note).
