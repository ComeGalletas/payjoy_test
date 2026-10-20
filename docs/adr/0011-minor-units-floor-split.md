# 0011 — Minor units, floor split and English display

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner
- **Supersedes:** 0007

## Context

ADR 0007 rounded each installment to the nearest cent and let the last one absorb the difference.
The review showed this can make the last installment zero or negative (1.00 over 60 → 0.02 each
and a last of −0.18), and that fixing 2 decimals for every currency is wrong for JPY and CLP
(0 decimals) and loses value for KWD, BHD and OMR (3) and CLF (4). The owner chose per-currency
decimals, the last installment absorbing the remainder with a clear message, and English output.

## Decision

- The API and storage keep full precision (`rate`, `converted`, `installment_amount` unrounded;
  `serialize_precision = -1` set at bootstrap).
- `Convert/MinorUnits` holds a static ISO 4217 exponent table. Unknown codes use 2 and are flagged
  in the event's `meta`.
- `InstallmentCalculator::display()` works in integer minor units:
  `total_m = round(round(converted, e) × 10^e)`, `base_m = intdiv(total_m, n)`,
  `last_m = base_m + total_m % n`. If `total_m < n`, the request is rejected with
  `VALIDATION_INSTALLMENTS`.
- Display strings are built from integers in English format (`,` thousands, `.` decimal, `e`
  fraction digits) and include `regular_count` and `even` so templates can say "2 monthly payments
  of 348.60 BRL and a final payment of 348.62 BRL".
- Only `display()` rounds money and rates; every front-end shows `display` verbatim.

## Consequences

- Installments are never zero or negative, and the displayed installments always sum exactly to
  the displayed total.
- The last installment can be up to `n − 1` minor units above the others; the bot states it
  explicitly instead of hiding it.
- The 200 / 3 example becomes 66.66, 66.66, 66.68.
- If exact decimal arithmetic is ever required, a new ADR adds `bcmath`.
