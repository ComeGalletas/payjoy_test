# 0023 — Reports: period, cohort funnel, allow-listed labels and CSV

- **Status:** Accepted
- **Date:** 2026-10-22
- **Deciders:** owner
- **Relates to:** 0011, 0012, 0016, 0017, 0019, 0021, 0022

## Context

P1-08 (PR #23, three rounds) built `GET /reports`, `bin/report.php` and `GET /health`. PLAN §6
and §10 fix the report's sections and the acceptance test, but left open the period, what the
funnel counts, how labels taken from events are shown, the CSV layout and the CLI. The builder
chose these and listed seven decisions; the owner accepted all of them as built (2026-10-22), with
no rate limit on `/reports` and `/health` until P3-01. The `/health` decisions are recorded in the
ADR 0019 amendment, the quota ones in the ADR 0012 second amendment and the stored rate in the
ADR 0011 second amendment; this ADR covers the reports.

The round-1 tester found that the report sweeps at most 500 expired sessions, that `9999-12-31`
crashes into a 500, that labels copied from events can show a seeded IP or key, that a `;` opens
a formula in a semicolon-locale spreadsheet, that a digit-only step crashes the table and that
cents sums can overflow to a float. The round-1 judge found that the funnel's step rows don't add
up. The owner chose the full round 2; the co-leads' round-2 brief, from the tester's and judge's
findings, required the year cap (item 1), the cents range check (item 4), the bounded sweep
(item 5), funnel rows that add up (item 6), the label allow-lists and string step labels
(item 8), the semicolon-safe CSV (item 9) and the `/health` rules (ADR 0019 amendment). After
round 2 the owner asked for a short round 3 and renamed the funnel field `answered` to `passed`;
the co-leads' round-3 brief covered the step pattern and the `other` count (item 8) and the
placement rules (item 7). How each is built, and everything else below, is the builder's design,
which the tester and judge accepted.

## Decision

1. **Period (owner decision).** `from` and `to` are `YYYY-MM-DD` dates in `reports.timezone`,
   both days included, in years 1970 to 9998 (the period ends at the start of the day after `to`,
   which must still have a four-digit year). A missing `to` is today in the zone and a missing
   `from` is six days before `to`, so the default is the last 7 days; a period is at most 366
   days. Days are counted on calendar dates and each day's start
   is taken in the zone, so a day can be 23 or 25 hours. A bad query answers 400 `BAD_REQUEST`
   with a fixed operator text, which widens that code beyond `/bot/message` (PLAN §5).
2. **Sections.** `summary`, `days`, `currencies`, `countries`, `funnel` (per channel and step),
   `errors` (by code), `upstream` (per endpoint), `requests` (the request log by kind `api`,
   `widget_asset` or `favicon`, by status class and blocked), `ip_blocks` (`ip.block` events by
   source and reason, `ip.unblock` by source) and `notes`. The SQL is portable (PLAN §8): no date
   or JSON functions, and `meta` is decoded in PHP.
3. **Counting.**
   - A `/convert` 4xx or 503 answered by the handler is counted once, as an error, although it
     writes both an `api.request` and an `error` event.
   - Real upstream calls per endpoint are `miss` and `stale` lookups minus those with
     `reason=no_key`; a `blocked` lookup, stale or not, made no call. `/codes` has no caller in
     Phase 1, and a note says so.
   - Request-log counts cover only retained rows (`requests.retention_days`); a note says so.
4. **Money.** USD sums are integer sums of `amount_usd_cents`, and a row counts only when its
   cents are an integer from 1 to `amount.max` × 100. Any other value is counted in
   `summary.unreadable_amounts`, with a note. Only `ReportFormatter` formats money, from integer
   cents, with no `round`, `number_format` or float (INV-01). Conversions stored before P1-08 have
   no rate and are counted in `summary.conversions_missing_rate` (ADR 0011 second amendment).
5. **Sweep first.** The report sweeps expired sessions before counting (ADR 0021 item 7),
   repeating while a pass closes a full batch of 500, at most 20 passes. At the bound, or when the
   sweep fails, the report still runs and carries a note.
6. **Funnel (owner decision; `passed` by owner decision in round 3).** The funnel follows the
   cohort of sessions that started in the period, per channel, to one final position: completed at
   `result`, abandoned at a step, open at its `sessions.step`, or `never_answered` (at `amount`)
   for an ended session with no answer at all, valid or not (no `bot.step` event). Per step:
   - `reached`: final position at this step or a later one;
   - `passed`: final position past this step, which is the next row's `reached` (a session a
     conversion retry sent back counts at the earlier step); on `result`, `completed` replaces it,
     and that row has no `retries`;
   - `abandoned`, `never_answered` and `open`: final position at this step;
   - `retries`: failed answers at this step, conversion retries included.

   So `reached = passed + abandoned + never_answered + open` on every row, and
   `started = completed + abandoned + never_answered + open` per channel. Never-answered sessions
   are still `bot.session_abandoned` events in `summary` and `days`.
7. **Placement rules (round 3).**
   - A session that ended, or still waits, at a step outside the flow's order (a renamed step,
     `other` or a null step) is placed at the step after the furthest known step it answered
     correctly (`amount` if none), but never past `installments`, so `amount.reached` equals
     `started`. The out-of-flow step still gets its own row, which shows only its retries
     (`reached` 0). An open session with no `sessions` row is placed the same way.
   - A session whose `sessions` row is closed but whose end event was never written (INV-07) is
     counted by the row's status: completed, or abandoned at the row's step with code `other`
     (`never_answered` if it has no `bot.step` event). That `other` isn't in the label note's
     count.

   Each rule adds a note when it applies.
8. **Labels.** Every label taken from an event is checked against its label allow-list, and
   anything else is counted as `other`, with a note giving the count (values only the funnel
   folds and error events with no code included). For currency this is a pattern, not the rate
   table's allow-list:
   - endpoint: the keys of `ExchangeRateClient::ENDPOINTS`;
   - error codes and `refresh_error`: `ErrorCodes`;
   - `block_source`: `auto` or `manual` (`none` when null); block `reason`: `rate_limit_hits`,
     `error_responses`, `given` or `default`;
   - channel: `Metrics::CHANNELS`; currency: `^[A-Z]{3}$`; country: a `CurrencyCountryMap` name;
   - step: the flow loader's `FlowDefinition::ID_PATTERN`.

   So no IP, key or free text reaches any format, whatever the event rows hold (INV-03,
   ADR 0016).
9. **Formats.** JSON is the envelope, with ISO times and an `amount_usd` string next to every
   `amount_usd_cents`. CSV is long format (`section,key,subkey,metric,value`), RFC 4180 with CRLF,
   sent as a `TextResponse` (`text/csv; charset=utf-8`, attachment, `nosniff`). Both send
   `Cache-Control: no-store`. The terminal table is for `bin/report.php`.
   **CSV safety:** a cell holding `"`, `,`, `;`, CR or LF is quoted, and the cell and each
   `;`-separated segment get a leading `'` when they start with `=`, `+`, `-`, `@`, a tab or a CR,
   or with whitespace before one of `=`, `+`, `-` or `@`. Spreadsheets in semicolon locales, such
   as es-CO, split cells on `;`.
10. **Access.** `/reports` sits behind `AdminAuth` only (an unset token refuses everything) and
    has no rate limit until P3-01 (owner decision; ADR 0016 fourth amendment).
11. **CLI (owner decision).** `bin/report.php [--from] [--to] [--format=table|csv|json]`, default
    `table`. Exit 0 when done (`--help` and `-h` too), 1 on failure with only the exception class,
    or a fixed text for a missing database file, on stderr, and 2 for usage errors. A missing
    SQLite file is refused and never created.

## Consequences

- One query feeds `/reports` and the CLI. A report can't print an IP, a key or a formula, and
  every number in it is an integer.
- The acceptance check "a currency named `=CMD()` is escaped in CSV" is met twice: such a
  currency folds to `other` and never reaches a cell, and `CsvWriter` guards it directly
  (`CsvWriterTest`, `ReportFormatterTest`, `P1_08_ReportsTest`).
- **Known limits:**
  - `ReportQuery::STEPS` is a fixed list (`amount`, `currency`, `installments`, `result`). A step
    added to the flow must be added there too, or its sessions are placed as "outside the flow"
    (L-04: derive it from `FlowDefinition`).
  - The "outside the flow" note also counts open sessions waiting on an unknown step, although
    its text says "ended" (L-04).
  - Renaming or removing a map country or an error code later relabels older events as `other`.
  - Lowering `amount.max` later (it becomes editable in P3-02) counts older conversions above it
    as unreadable.
  - A report that runs between a session's row save and its end-event write (ADR 0021 item 6)
    shows a spurious "closed without an end event" note. A session whose end event is missing and
    whose row was pruned counts as open.
  - Report cost grows with the period's events; no index was added.
  - A `rate_scaled` above 2^53 (a rate above about 900,000 per USD, which IRR already is) can't
    be read exactly as a JavaScript Number, so a JavaScript consumer (the P3 dashboard) must read
    it as text.
  - `ReportQuery` is about 900 lines; split it into per-section parts before the P3 rollups
    (L-04).
