# Reports

One query (`Metrics/ReportQuery`) feeds two ways to read the numbers: `GET /reports` (JSON or
CSV) and `php api/bin/report.php` (a terminal table, CSV or JSON). Both cover a **report period**
of whole days and give the same sections. Every number is an integer count or an integer sum of
cents; no report ever shows an IP, a path, the API key or upstream text (ADR 0023).

## Getting a report

### `GET /reports`

```
curl.exe -H "Authorization: Bearer <your ADMIN_TOKEN>" "http://127.0.0.1:8080/reports?from=2026-10-01&to=2026-10-31&format=csv"
```

(`curl` on Linux and macOS.)

- **Auth:** the admin token, only as `Authorization: Bearer <ADMIN_TOKEN>`, never in the URL.
  A missing or wrong token, or an unset `ADMIN_TOKEN`, answers 401 `ADMIN_UNAUTHORIZED`, which
  counts toward the IP's automatic block like any 4xx. There is no rate limit on `/reports` until
  P3-01.
- **`from` and `to`:** days written `YYYY-MM-DD` in `reports.timezone` (America/Bogota), both
  included, in the years 1970 to 9998. A missing `to` is today in that zone; a missing `from` is
  six days before `to`, so the default is **the last 7 days, today included**. A period is at
  most 366 days. Days are calendar days in the zone, so one can be 23 or 25 hours long where the
  zone has daylight saving.
- **`format`:** `json` (the default) or `csv`.
- Other parameters are ignored. A bad date, `from` after `to`, a longer period, another format,
  an empty value or an array value (`from[]=...`) answers 400 `BAD_REQUEST`: "The report query
  could not be read. Use from and to as YYYY-MM-DD, from not after to, at most 366 days, and
  format json or csv."
- JSON is the usual envelope (`{"ok": true, "data": {...}}`), with times as ISO 8601 UTC and an
  `amount_usd` string next to every `amount_usd_cents`. CSV is `text/csv; charset=utf-8`, sent
  as the attachment `fx-report-<from>-to-<to>.csv`. Both carry `Cache-Control: no-store`.

### `php api/bin/report.php`

```
php api/bin/report.php
php api/bin/report.php --from=2026-10-01 --to=2026-10-31 --format=csv
```

- Options: `--from=YYYY-MM-DD`, `--to=YYYY-MM-DD` (the same rules as above) and
  `--format=table|csv|json`, each at most once. `table`, for a terminal, is the default; `csv` is
  the `/reports?format=csv` text; `json` is the `/reports` data, pretty-printed, without the
  envelope. `--help` (or `-h`) prints the usage.
- Exit codes: 0 done; 1 failed, with only the exception class on stderr (or
  `report: failed: no database file (run php bin/migrate.php first)`); 2 usage errors (an
  unknown or repeated option, a bad date or format, `from` after `to`, more than 366 days).
- It refuses a database behind the code or with a corrupt schema version, and a missing SQLite
  file, which it never creates.

### Every report sweeps first

Before counting, a report closes open sessions whose time is up, as the bot would on its next
message: each gets a `bot.session_abandoned` event with `BOT_SESSION_EXPIRED` at its last step.
So **running a report can write events.** It sweeps in batches of 500, repeating while a batch is
full, at most 20 times; if that bound is reached, or the sweep fails, the report still runs and
says so in a note.

## Sections and fields

Unless said otherwise, a section counts the events whose time falls inside the period.

### `period` and `generated_at`

`from`, `to`, `timezone`, `days` (the number of days), and `start` and `end` (the period's first
second and the first second after it, in UTC; the CSV has the same rows). `generated_at` is when
the report ran.

### `summary`

| Field | Counts |
|---|---|
| `sessions_started` | `bot.session_start` events |
| `sessions_completed` | `bot.session_completed` events |
| `sessions_abandoned` | `bot.session_abandoned` events, never-answered sessions included |
| `conversions` | Successful conversions: `/convert` answers (an `api.request` with status ok) plus completed bot sessions |
| `conversions_api`, `conversions_bot` | The same, split by where it ran |
| `amount_usd_cents`, `amount_usd` | The USD total of those conversions: an integer sum of cents, and the same as text |
| `unreadable_amounts` | Conversions whose cents aren't a whole number from 1 to `amount.max` × 100; counted with no USD |
| `api_requests` | `/convert` requests its handler answered (`api.request` events) |
| `api_errors` | Those answered with an error |
| `error_responses` | Error responses (`error` events): every 4xx and 5xx the app answered after routing, rate-limited (429) and `INTERNAL` ones included; blocked requests excluded |
| `bot_errors` | Bot events with an error code: failed answers (`bot.step`) and abandoned sessions |
| `upstream_calls` | Real calls to upstream, every endpoint (see `upstream`) |
| `conversions_missing_rate` | Conversions recorded without the rate they used (before P1-08) |

In the table, `USD converted` is `amount_usd`. The summary counts events in the period, so a
session that started before the period and completed in it counts in `sessions_completed` but
not in the funnel.

### `days`

One row per day of the period (`date`) with the summary's `sessions_started`,
`sessions_completed`, `sessions_abandoned`, `conversions`, `amount_usd_cents` (and
`amount_usd`), `api_requests`, `error_responses` and `upstream_calls`, split on midnight in
`reports.timezone`.

### `currencies` and `countries`

One row per currency code, and one per country the user **typed** in the bot (a typed code like
`EUR` has no country):

- `requested`: a `/convert` request that got as far as a valid currency, or a currency the bot
  accepted;
- `conversions`, `conversions_api`, `conversions_bot` and `amount_usd_cents` (with `amount_usd`):
  as in the summary, for that currency or country.

Converted amounts in the target currency are never shown: Phase 1 reports don't recompute them.

### `funnel`

One block per channel (`static`, `cli`, `typebot`). It follows a **cohort**: the sessions that
**started in the period**, followed to their end even if that comes after the period. Each
session has one **final position**: completed at `result`; abandoned at a step; still open at the
step it waits on; or **never answered**, at `amount`, for a session that ended without a single
answer, valid or not (for example a widget that was focused and left).

Per channel: `started`, `completed`, `abandoned`, `never_answered`, `open`, with
`started = completed + abandoned + never_answered + open`, and `abandoned_by_code` (the end code
of each abandoned session: `BOT_MAX_RETRIES`, `BOT_SESSION_EXPIRED`, or an upstream code).
Never-answered sessions are not in `abandoned` here, but they are `bot.session_abandoned` events,
so the summary and days count them as abandoned.

Per step (`amount`, `currency`, `installments`, `result`, in the flow's order):

| Field | Meaning |
|---|---|
| `reached` | Sessions whose final position is this step or a later one |
| `passed` | Sessions whose final position is past this step: the next row's `reached` |
| `completed` | On `result` only, in place of `passed` |
| `retries` | Failed answers at this step, conversion retries included (not on `result`) |
| `abandoned`, `never_answered`, `open` | Sessions whose final position is this step |

**Reading it:** on every row, `reached = passed + abandoned + never_answered + open` (with
`completed` for `passed` on `result`). `amount.reached` is `started`, and each row's `passed` is
where the next row starts, so the drop between two rows is exactly the sessions that ended or
wait there. A session that a failed conversion sent back to an earlier step (`too_large` to the
amount, `not_in_table` to the currency, `below_minor_unit` to the installments) counts at that
earlier step. Open sessions become abandoned (or never answered) once
`bot.session_ttl_minutes` (30) pass without a message and a sweep closes them.

Two placement rules keep the rows adding up (ADR 0023 item 7), each with a note:

- A session that ended, or waits, at a step outside the flow's order (a renamed step, `other`, or
  none) is placed at the step after the furthest known step it answered (`amount` if none), never
  past `installments`. The unknown step still gets its own row, which shows only its retries.
- A session whose `sessions` row is closed but whose end event was never written (the write
  failed) is counted by the row: completed, or abandoned at the row's step with code `other`, or
  never answered if it has no answer.

### `errors`

One row per error code, with `http` (error responses, one per `error` event), `bot` (bot events
with that code) and `total`. A `/convert` 4xx or 503 writes both an `api.request` and an `error`
event; it is counted once, here. Upstream failures are counted in `upstream`, not here, but a bot
session that ended on an upstream error counts its code under `bot`.

### `upstream`

- `calls`: real upstream calls in the period, every endpoint; `breaker_resets`: the
  `upstream.breaker_reset` events from `php api/bin/upstream.php reset`.
- `endpoints`: one row each for `latest` (`/latest/USD`), `codes` and `quota`, with `lookups`
  (every `upstream.call` event), `hit`, `miss`, `stale`, `blocked`, `unknown` (an event with no
  known result), `calls`, `no_key` and `failed_calls`. A real call is a `miss` or `stale` lookup,
  except those with `reason=no_key` (counted in `no_key`, since no key means no call); a `hit` or
  a `blocked` lookup made no call. A call failed when its event has a `reason` (miss) or a
  `refresh_error` (stale).
- `failures`: failed calls per endpoint and error code.

**Quota used:** upstream counts both kinds of call against the monthly quota (ADR 0012 third
amendment): the `latest` row's `calls` (the `/latest/USD` refreshes, about 30 a month) and the
`quota` row's `calls` (the admin view of `/health`, at most once per
`health.quota_cache_minutes`). `summary.upstream_calls` and `upstream.calls` add them up: this
database's calls in the period, an upper bound on what upstream counted, since a call that failed
before reaching upstream is counted too. Upstream's quota month starts on its
`refresh_day_of_month` (the admin `/health` view's `upstream_refresh_date`), not on the report's
dates, and calls made with the same key elsewhere don't appear here. Upstream's own remaining
count lags (in P1-10 a call wasn't shown a minute later). `/codes` is never called in Phase 1.

### `requests` (the request log)

`total` and `blocked`, and one row per **request kind**: `widget_asset` (`/bot/widget`,
`/bot/widget.js`, `/bot/widget.css`, `/bot/widget.svg`), `favicon` (`/favicon.ico`) and `api`
(every other path, unknown ones included), each with `total`, `2xx`, `3xx`, `4xx`, `5xx`,
`other` and `blocked` (requests answered 403 `IP_BLOCKED`, also counted in `4xx`). Paths and IPs
are never listed. The request log keeps rows `requests.retention_days` (30), so older periods
show fewer requests, with a note.

### `ip_blocks`

- `blocks`: `ip.block` events by `block_source` (`auto` or `manual`) and `reason`
  (`rate_limit_hits` or `error_responses` for automatic blocks; `given` or `default` for manual
  ones, by whether the operator gave a reason), with `count` and `extended` (automatic blocks that
  extended an active one).
- `unblocks`: `ip.unblock` events by the `block_source` of the block they lifted (`none` when
  the IP had no active block), with `count`.

### Labels and `other`

Every label taken from an event is checked against a fixed list: error codes against
`ErrorCodes`, channels, endpoints, block sources and reasons against their known words, steps
against the flow's id pattern, countries against the currency-country map, and currencies against
`^[A-Z]{3}$`. Anything else is counted under the label `other`, with a note. So a damaged or
hand-edited row can't put free text, an IP or a key into a report.

## Notes

`notes` is a list of sentences. Three are always there:

- "The funnel counts the sessions that started in the period, followed to their end."
- "Upstream calls are /latest/USD refreshes (miss and stale lookups, except reason no_key) and
  /quota calls from the admin health view; /codes is never called in Phase 1." This is the
  `/codes` exclusion.
- "Each error response is counted once (its error event); a /convert error's api.request event
  is not counted again."

The others appear only when they apply (`N` is a count):

| Note | When, and what to do |
|---|---|
| "Expired sessions could not be closed before counting, so some sessions may still show as open." | The sweep failed (for example the database was locked); run the report again. |
| "N expired sessions were closed before counting; more may remain and show as open." | **The sweep bound:** 20 batches of 500 were closed and more may wait; run the report again. |
| "Converted amounts are not recomputed; N conversion(s) in this period have no stored rate_scaled (recorded before P1-08)." | **No stored rate:** conversions from before P1-08, which didn't store their rate. |
| "N conversion(s) have an amount that is not whole cents from 0.01 to amount.max; they are counted with no USD." | **Unreadable amounts:** rows whose cents aren't valid (damaged, or above a lowered `amount.max`). |
| "N value(s) outside the known codes, currencies, countries, steps or words are counted as "other"." | **`other`:** labels outside their lists (see above). |
| "N session(s) ended at a step outside the flow's order; the funnel places each at the known step after its last answer." | **Outside the flow:** a renamed or unknown step (it also counts sessions still open at such a step). |
| "N session(s) were closed without an end event (its write failed); the funnel counts them by their sessions row." | **Closed without an end event:** an end-event write failed; a report that runs in the instant between a session's save and its event can show this once. |
| "Request-log counts cover only the last 30 days (requests.retention_days); older rows are pruned." | **Request-log retention:** the period starts more than 30 days ago. |

## CSV

Long format, one value per row, so it loads into a spreadsheet as is:

```
section,key,subkey,metric,value
```

- `section` names the part: `period`, `summary`, `day`, `currency`, `country`, `funnel`,
  `funnel_step`, `funnel_abandoned`, `error`, `upstream`, `upstream_endpoint`,
  `upstream_failure`, `requests`, `requests_kind`, `ip_block`, `ip_unblock`, `note`.
- `key` is the row's label (a date, a currency, a country, a channel, an error code, an
  endpoint, a request kind, a block source); `subkey` is the second label (the funnel step, the
  abandon code, the failed call's code, the block reason); `metric` is the field; `value` is the
  number or text. Money is a plain decimal here (`amount_usd` `6089.88`), next to its
  `amount_usd_cents`.
- RFC 4180 with CRLF line ends, the header first.

**CSV safety.** A cell holding `"`, `,`, `;`, CR or LF is quoted, with each `"` doubled. The
cell, and each `;`-separated part of it, gets a leading `'` when it starts with `=`, `+`, `-`,
`@`, a tab or a CR, or with whitespace before one of `=`, `+`, `-`, `@`, so a spreadsheet shows
it as text instead of running it. The `;` rule matters because spreadsheets in semicolon locales,
such as es-CO, split cells on `;`. Report labels are already limited to their lists, so this is a
second guard.

## Example: the demo data

On a new throwaway database (see `INSTALL_LOCAL.md` section 6): `php api/bin/migrate.php`, then
`php api/db/seed_demo.php`, then `php api/bin/report.php --from=<first date> --to=<last date>`
with the dates the seed printed. On 2026-10-22 it showed this (the report's first two lines,
with the dates, are left out). The seed writes the same rows every time, so your numbers match
over those dates (or with no options on the day you seed), except for the two open sessions:
within 30 minutes of seeding they expire and the next sweep closes them, and then the static one
is abandoned at `currency` and the CLI one never answered.

<!-- seed_demo example: table -->
```text
Summary
  Sessions started                         14
  Sessions completed                        6
  Sessions abandoned                        6
  Conversions                              11
    via /convert                            5
    via the bot                             6
    with an unreadable amount               0
  USD converted                      6,089.88
  /convert requests                         7
    answered with an error                  2
  Error responses                          11
  Bot errors                               17
  Upstream calls                            0
  Conversions without a stored rate         0

Funnel, channel static (sessions started in the period)
  Step          Reached  Passed  Retries  Completed  Abandoned  Never answered  Open
  amount              9       7        3          -          1               1     0
  currency            7       6        1          -          0               0     1
  installments        6       4        4          -          2               0     0
  result              4       -        -          4          0               0     0
  started 9, completed 4, abandoned 3, never answered 1, still open 1
  abandoned by code: BOT_MAX_RETRIES 2, BOT_SESSION_EXPIRED 1
```

How to read the static funnel: 9 sessions started. At `amount`, 1 was abandoned after three
unreadable amounts (`retries` 3) and 1 never answered, so 7 passed. At `currency`, 1 is still
waiting, so 6 passed; the one retry is an ambiguous currency answered on the second try. At
`installments`, 2 were abandoned (one after three bad counts, one that expired) and 4 passed, and
all 4 completed: 9 = 4 + 3 + 1 + 1. The summary's 6 abandoned sessions are these 3, the never
answered one and the CLI's 2. The demo's 11 rate lookups are all cache hits, so
`Upstream calls` is 0: the `latest` row shows only real calls.

The same numbers in the CSV (`--format=csv`), summary and static funnel:

<!-- seed_demo example: csv -->
```text
summary,,,sessions_started,14
summary,,,sessions_completed,6
summary,,,sessions_abandoned,6
summary,,,conversions,11
summary,,,amount_usd_cents,608988
summary,,,amount_usd,6089.88
summary,,,api_requests,7
summary,,,error_responses,11
summary,,,upstream_calls,0
summary,,,conversions_api,5
summary,,,conversions_bot,6
summary,,,unreadable_amounts,0
summary,,,api_errors,2
summary,,,bot_errors,17
summary,,,conversions_missing_rate,0

funnel,static,,started,9
funnel,static,,completed,4
funnel,static,,abandoned,3
funnel,static,,never_answered,1
funnel,static,,open,1
funnel_step,static,amount,reached,9
funnel_step,static,amount,passed,7
funnel_step,static,amount,retries,3
funnel_step,static,amount,abandoned,1
funnel_step,static,amount,never_answered,1
funnel_step,static,amount,open,0
```

`P1_09_SeedAndDocsTest` checks both blocks against a fresh run, so they stay true.
