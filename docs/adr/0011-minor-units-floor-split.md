# 0011 — Minor units, floor split and English display

- **Status:** Accepted (storage clause superseded by 0017; amended 2026-10-21: exact display
  total, see "Amendment"; amended again 2026-10-22: the rate per conversion, see "Second
  amendment")
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
  `serialize_precision = -1` set at bootstrap). (Storage: see 0017.)
- `Convert/MinorUnits` holds a static ISO 4217 exponent table. Unknown codes use 2 and are flagged
  in the event's `meta`.
- `InstallmentCalculator::display()` works in integer minor units:
  `total_m = round(round(converted, e) × 10^e)` (replaced by an exact integer formula, see
  "Amendment"), `base_m = intdiv(total_m, n)`,
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
- If exact decimal arithmetic is ever required, a new ADR adds `bcmath`. (The amendment makes the
  display total exact without `bcmath`.)

## Amendment (2026-10-21): exact display total, grouped strings, new reasons

**Context.** P1-05 (PR #16, two rounds) built `display()` on the float formula above. The round-1
tester compared it with an exact bcmath oracle over 2.3M cases. On large totals, `total_m` was off
by one minor unit in about 3.3% of IRR conversions, 0.5% of LBP, 0.24% of IDR and 0.05% of COP
(e.g. $999,999.24 at IRR 1,731,936.861971 showed `…698.99`; the exact value is `…698.98`). Most of
the error came from PHP 8.3's `round()` rounding to 15 significant digits first, which turns a
correctly placed float into a false tie. The judge showed an exact integer computation is short
and needs no `bcmath`. The owner then decided items 1–3 below. Items 4–6 are the builder's
design, which the tester and judge accepted.

**Decision.**
1. **Exact total (owner decision).** `total_m` is computed with integers only, from the canonical
   amount's cents and the stored `rate_scaled` (ADR 0017):
   `total_m = round_half_away_from_zero(cents × rate_scaled × 10^e / 10^12)`. The product doesn't
   fit 64 bits, so `rate_scaled` is split into 10^9 limbs and the division carries its remainder.
   No floats and no `bcmath` are used in that path. The inner `round()` above is gone.
2. **`display.amount_usd` is grouped (owner decision):** `1,000,000.00`, like the other money
   strings; `200.00` is unchanged. The raw `amount_usd` number is unchanged.
3. **`display.rate` (ratified as built):** rounded from `rate_scaled` with integer arithmetic, half
   away from zero at 6 decimals, trailing zeros trimmed, integer part grouped
   (`1,731,936.861971`). A rate below 0.0000005 would show `0`; no real rate is that small.
4. **`display()` signature:** `display(cents, rate_scaled, n, currency, rate_as_of)`. The 2^53 limit
   is checked on the exact `total_m`. `ConvertService` checks it first and answers
   `VALIDATION_AMOUNT` with reason `too_large`. Inside `display()` a total of 2^53 or more is a
   programming error (`InvalidArgumentException`, so a removed check shows up as a 500, not a
   wrong number). The total is now exact in int64 well past 2^53; the limit is kept as PLAN §3a's
   bound because the raw `converted` and `installment_amount` are doubles and JSON numbers, which
   above 2^53 minor units can't resolve every minor unit, also for any JavaScript client that reads
   the raw numbers (lead's rationale).
5. **Reasons:** `too_large` (amount; the total reaches 2^53 minor units; it answers with the
   `VALIDATION_AMOUNT` text, and no real rate reaches it) and `below_minor_unit` (installments;
   `total_m < n`). `too_large` is a 400, so it counts toward `block.error_responses` (ADR 0016).
   That is acceptable while it can't be reached: at `amount.max` (1,000,000) the largest real
   total, IRR, is about 1.7 × 10^14, about 50× below 2^53. Revisit it if a rate comes within
   reach.
6. **`MinorUnits` choices:** XDR uses 2 (ISO lists none); the territory codes FOK, GGP, IMP, JEP,
   KID and TVD take their parent currency's exponent; the legacy codes ANG, SLL and ZWL are listed.

**Consequences.**
- The display is exact for the stored rate: the round-2 tester's bcmath oracle found 0 mismatches
  in 2.5M cases (e = 0, 2, 3, 4, amounts to 1,000,000.00, rates up to the storable maximum), and
  the round-2 judge found 0 in 600,728 cases of its own. It can be reproduced from the amount's
  cents and the `rate_scaled` used. Since P1-08 each conversion stores the rate it used (see
  "Second amendment").
- The raw `converted` and `installment_amount` stay full-precision floats, computed from the
  nearest doubles of the amount and the rate (not the nearest double of the exact product), so
  their last digits can differ from the exact `display` strings. Front-ends show `display` only.
- The tests carry their own schoolbook decimal-string oracle, so they need no `bcmath`.

## Second amendment (2026-10-22): the rate is stored per conversion

Recorded from P1-08 (PR #23, ADR 0023); owner decision 4.

- A successful `/convert` stores `rate_scaled` and `rate_as_of` in its `api.request` meta, and a
  completed bot session in its `bot.session_completed` meta, as JSON integers (INV-09 is
  unaffected). A conversion's display can now be recomputed from its cents and its rate.
- Rows written before P1-08 have neither; reports count them in `summary.conversions_missing_rate`
  with a note. Phase 1 reports don't recompute converted amounts.
- A `rate_scaled` above 2^53 (a rate above about 900,000 per USD, which IRR already is) can't be
  read exactly as a JavaScript Number, so a JavaScript consumer must read it as text.
