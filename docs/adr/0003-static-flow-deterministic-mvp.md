# 0003 — Static YAML flow and deterministic parsing for the MVP

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The bot follows a strict linear pipeline (greet → amount → currency → installments → result). An
LLM could parse freer input but adds cost, latency, an extra failure mode and prompt-injection
risk. The design discussion compared Landbot, Typebot and Botpress.

## Decision

The MVP flow is data in `flows/default.yaml`, run by a PHP state machine (`Flow/FlowEngine`).
Input is parsed deterministically: validators for amount and installments; regex, allow-list and a
static synonym/country table for currency. No LLM in Phase 1. Phase 2 adds an optional,
feature-flagged normalizer (`llm.enabled`, default `false`) in front of the same validators; it
never replaces them.

## Consequences

- Phase 1 behaviour is fully testable and repeatable.
- Unusual phrasing gets a friendly retry instead of a guess.
- Non-developers can edit wording and buttons in the YAML without touching PHP.
