# 0017 — Store money and rates as integers in fixed units

- **Status:** Accepted
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
- Exchange rates are stored as `rate_scaled BIGINT = round(rate × 10^10)`, a fixed scale of ten
  decimals. The largest rates (LBP, about 89,500 × 10^10 ≈ 9 × 10^14) fit easily in 64 bits and
  stay below 2^53, so the float error in `rate × 10^10` stays far below 0.5 and `round()` gives
  the correct integer.
- Calculations stay as in ADR 0011: `converted = amount × rate` is a PHP float, and splitting and
  display use integer minor units. Only storage changes.
- `Database::floatParam()` is removed once nothing binds floats.

## Consequences

- Exact, portable storage on SQLite and Postgres, with no locale or precision dependence.
- Upstream rates with more than ten decimals are rounded at the tenth; upstream rates normally
  have four to six. Listed as a known limit.
- Columns are renamed (`amount_usd` → `amount_usd_cents`, `rate` → `rate_scaled`); reports divide
  when they present values (`ReportFormatter`).
- P1-02 applies the schema change and updates `Metrics`; P1-04 writes `rate_scaled`.
