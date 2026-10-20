# 0012 — Upstream: one `/latest/USD` call per refresh, local math, stale fallback

- **Status:** Accepted
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

The draft used `/pair/USD/{cy}/{amount}`, which costs one call per currency per day: ~160
currencies would need ~4,800 calls a month against a 1,500 quota. A cache miss also used upstream's
`conversion_result` while a hit recomputed locally, giving two code paths that could disagree. The
draft had no plan for upstream being down, and Guzzle exceptions include the URL, which contains
the key.

## Decision

- Call `/latest/USD` only when `now ≥ next_update_unix`; one call refreshes every rate (~30 calls a
  month). Its codes are the currency allow-list; `/codes` is used only for names.
- Always compute `converted = amount × rate` locally.
- On refresh failure, serve a cached table younger than `rates.max_stale_hours` with `stale: true`.
  Every response carries `rate_as_of`. A static `FallbackCurrencies` list validates codes on a cold
  start.
- `UpstreamBreaker` stops calls after `quota-reached` (until next month, UTC) and after
  `invalid-key` (until `bin/upstream.php reset`).
- The client catches every exception and maps it to an error code; exception text never leaves
  it. Tests inject a mocked handler; the test bootstrap forbids real network calls and uses a fake
  key.

## Consequences

- Quota use depends on time, not traffic, and can't be burned by a script.
- One code path for conversion.
- Users may see a result based on a rate up to `rates.max_stale_hours` old, clearly marked.
