# 0017 — Store money and rates as integers in fixed units

- **Status:** Accepted (amended 2026-10-21: rate scaling, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner
- **Supersedes:** the storage half of 0011's first decision bullet ("The API and storage keep full
  precision"); the API half stands

## Context

ADR 0011 keeps full precision in the API and storage. P1-01 found that PDO binds PHP floats as
strings using PHP's `precision` setting (14 digits), so `1045.82/3` was stored as
`348.60666666667`. The P1-01 fix, `Database::floatParam()`, binds 17-digit strings, but the review
found it locale-dependent (`%.17g`) and found `REAL` would be 4 bytes in Postgres. The owner
preferred to avoid passing amounts through strings at all.

Options considered: integers in fixed units; raising PHP `precision` globally; `DECIMAL`/`NUMERIC`
columns (not exact in SQLite, breaks ADR 0010); keeping `REAL` with the string bind.

## Decision

- No floats in the database.
- USD amounts are stored as integer cents: `amount_usd_cents INTEGER`. The canonical amount string
  (`^\d{1,7}(\.\d{1,2})?$`) is converted to cents with integer arithmetic, never through a float.
- Exchange rates are stored as `rate_scaled BIGINT`, the rate's decimal value at a fixed scale of
  ten decimals (`round(rate × 10^10)`, half away from zero). How it is computed is set by the
  amendment below.
- Calculations stay as in ADR 0011: `converted = amount × rate` is a PHP float, and splitting and
  display use integer minor units. Only storage changes.
- `Database::floatParam()` is removed once nothing binds floats.

## Consequences

- Exact, portable storage on SQLite and Postgres, with no locale or precision dependence.
- Upstream rates with more than ten decimals would be rounded at the tenth; the live table has at
  most six. Listed as a known limit.
- Columns are renamed (`amount_usd` → `amount_usd_cents`, `rate` → `rate_scaled`); reports divide
  when they present values (`ReportFormatter`).
- P1-02 applies the schema change and updates `Metrics`; P1-04 writes `rate_scaled`.

## Amendment (2026-10-21): scale rates from decimal strings

**Context.** The first version said `rate × 10^10` computed in floats rounds correctly below 2^53.
The P1-02 tester showed it doesn't: PHP's `round()` returns values ≥ 10^15 unchanged and `(int)`
truncates, so `439537.6134` became `4395376133999999` instead of `4395376134000000` (about 9% of
rates ≥ 100,000). Even ideal rounding of the float product is wrong in 4–6% of cases there.
A live ExchangeRate-API table (2026-10-21, 166 codes) has at most 6 decimals and 7 integer digits,
and its largest rate is IRR ≈ 1,731,936.86 — above the old 2^53 limit, so the first version would
have rejected a real currency.

**Decision.**
- `Convert/ScaledRate` never multiplies a float by 10^10. It takes the rate's decimal text — either
  upstream's string, or the shortest round-trip form PHP prints for the float
  (`serialize_precision = -1`) — and builds the scaled integer with integer arithmetic: integer
  digits × 10^10 plus the fraction padded or rounded to ten digits, half away from zero on the
  eleventh.
- Accepted rates: finite, > 0, scaling to at least 1, and below 900,000,000, so the scaled value
  fits a signed 64-bit integer (Postgres `BIGINT`) with margin. The earlier 2^53 limit is dropped.
- `toRate()` (scaled → float) stays approximate by design: calculations are floats (ADR 0011).

**Consequences.** Storage matches the decimal value upstream sent, for every accepted rate; a
randomized test sweep across the whole range checks it against a string-based reference.
