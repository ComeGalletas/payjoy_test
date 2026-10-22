# 0017 — Store money and rates as integers in fixed units

- **Status:** Accepted (amended 2026-10-21: rate scaling, see "Amendment"; amended again
  2026-10-21: `toRate()`, see "Second amendment")
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
the rates ≥ 100,000 in the round-1 tester's sample). Even perfect rounding of the float product is
wrong for a few percent of such rates, so fixing the rounding call alone isn't enough.
The lead measured a live ExchangeRate-API table on 2026-10-21 (the public no-key endpoint
`open.er-api.com/v6/latest/USD`, 166 codes; the keyed endpoint wasn't checked): at most 6
decimals and 7 integer digits, and a largest rate of IRR ≈ 1,731,936.86 — above the old 2^53
limit, so the first version would have rejected a real currency.

**Decision.**
- `Convert/ScaledRate` never multiplies a float by 10^10. It takes the rate's decimal text — either
  upstream's string, or the shortest round-trip form PHP prints for the float
  (`serialize_precision = -1`) — and builds the scaled integer with integer arithmetic: integer
  digits × 10^10 plus the fraction padded or rounded to ten digits, half away from zero on the
  eleventh.
- Accepted rates: finite, > 0, scaling to at least 1, and below 900,000,000, so the scaled value
  fits a signed 64-bit integer (Postgres `BIGINT`) with margin. The earlier 2^53 limit is dropped.
- `toRate()` (scaled → float) stays approximate by design: calculations are floats (ADR 0011).
  (Changed by the second amendment: it now gives the nearest double.)

**Consequences.** Storage equals upstream's decimal rounded half away from zero at ten decimals
when upstream's text is passed to `fromDecimalString`, or when the float came from a number with
15 or fewer significant digits (the live table has at most 13). A randomized test sweep across
the whole range checks the arithmetic against a string-based reference. The companion helper
`Convert/UsdCents` converts canonical USD strings to cents and back with integer arithmetic only.

## Second amendment (2026-10-21): `toRate()` gives the nearest double

Decided while building P1-05 (PR #16); the builder's choice, accepted by the tester and judge.

- `ScaledRate::toDecimalString()` builds the rate's exact decimal text from `rate_scaled` with
  integer arithmetic, and `toRate()` parses that text. The result is the nearest double for every
  storable rate. The old `scaled / 10^10` was only approximate above 2^53 scaled (IRR).
- `RateResult::rate()` uses the same path, so there is one way from the stored integer to a float.
- The display no longer depends on this float: ADR 0011's amendment computes it from
  `rate_scaled` directly. The float feeds only the raw `rate`, `converted` and `installment_amount`.
