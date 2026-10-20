# 0006 — `amount` is the total USD price

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The draft plan's API treated `amount` as a total, but the bot asked "What is the installment amount
in USD?", which reads as a per-installment amount. The owner clarified the use case: *"I bought my
phone for $200 USD. How much is my monthly installment in my local currency?"*

## Decision

`amount` (and `amount_usd`) is always the **total** USD price. `converted = amount × rate`, and
`installment_amount = converted / installments`. The bot asks "What was the total price in USD?"

## Consequences

- No endpoint accepts a per-installment amount.
- Bot wording, docs and tests use "total price" consistently (`CONTEXT.md`).
