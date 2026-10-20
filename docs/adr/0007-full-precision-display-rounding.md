# 0007 — Full precision end to end; display-only rounding

- **Status:** Superseded by 0011
- **Date:** 2026-10-20
- **Deciders:** owner
- **Changes:** the draft plan's rule "`converted` and `installment_amount` to 2 decimals"

## Context

The draft rounded `converted` and `installment_amount` to 2 decimals in the API. The owner wants
all value conserved: everything converted from USD must reach the target currency, with decimals
kept, and only the chatbot rounds to 2. Rounded installments can fail to add up to the rounded
total (100 / 3 → 33.33 × 3 = 99.99).

## Decision

- The API, storage and all calculations keep full precision. `rate`, `converted` and
  `installment_amount` are returned unrounded. Values are PHP `float` with
  `serialize_precision = -1`; stored as `REAL` / `DOUBLE PRECISION`.
- Only `InstallmentCalculator::display(converted, n)` rounds. `/convert` returns its result as a
  `display` block of 2-decimal strings: `converted`, `installment`, `last_installment`.
- `last_installment = round(converted, 2) − round(converted / n, 2) × (n − 1)`, computed in integer
  cents. The last installment absorbs the remainder, so displayed installments always sum to the
  displayed total.
- Every front-end shows `display` verbatim. The bot uses `say_uneven` when
  `last_installment ≠ installment`.

## Consequences

- One place owns rounding; CLI, widget and Typebot always agree.
- Floats give ~15–17 significant digits, enough for amounts up to the 1,000,000 cap. If exact
  decimal math is ever required, a new ADR adds `bcmath` and supersedes this one.
- Tests cover n = 1, exact splits, remainders both ways, n = max and amounts near the cap, and
  assert the displayed sum equals the displayed total (`docs/GUARDRAILS.md` INV-01, INV-02).
