# FX Installments

FX Installments turns a **total** price in USD into a target currency and splits it into monthly
installments. It is a PHP 8.3 API (no framework, SQLite through PDO) plus a deterministic chatbot,
and it gets its rates from ExchangeRate-API with one `/latest/USD` call per refresh.

- **Customers** use the bot: the built-in chat widget, the terminal bot, or any front-end that
  speaks `POST /bot/message` (Typebot comes in Phase 3).
- **Operators** use the reports (`GET /reports`, `php api/bin/report.php`), `GET /health` and a
  few command-line tools.

Phase 1 (the deterministic MVP) runs locally without Docker; see
[INSTALL_LOCAL.md](INSTALL_LOCAL.md).

## The parts

| Part | What it does | Where |
|---|---|---|
| `GET /convert?amount&cy&installments` | Converts a total USD amount and splits it; answers raw numbers and a `display` block | `api/src/Http/ConvertEndpoint.php`, `api/src/Convert/` |
| `POST /bot/message` | The static flow engine: one contract for every chat front-end | `api/src/Flow/`, `api/flows/default.yaml` |
| `GET /bot/widget` | The built-in chat widget, embeddable by iframe or one `<script>` | `api/resources/widget/` |
| `GET /reports` (admin) | Funnel, conversions, errors, upstream calls and the request log, as JSON or CSV | `api/src/Metrics/`; see [REPORTS.md](REPORTS.md) |
| `GET /health` | Public status for monitors; details with the admin token | `api/src/Http/HealthEndpoint.php` |
| `php api/bin/bot.php` | The bot in a terminal (channel `cli`) | |
| `php api/bin/report.php` | The report as a table, CSV or JSON | |
| `php api/bin/migrate.php` | Creates and upgrades the database; `--prune` deletes expired rows | |
| `php api/bin/ip.php` | Manual IP blocks: `block`, `unblock`, `list` | |
| `php api/bin/upstream.php` | The upstream breaker: `status`, `reset` | |
| `php api/db/seed_demo.php` | Demo data for the reports, on a new database | |

Every JSON answer is an envelope: `{"ok": true, "data": {...}}` or
`{"ok": false, "error": {"code": "...", "message_for_user": "..."}}`. Front-ends branch on `ok` and
show `message_for_user` as it is. The widget's files, the 204 `/favicon.ico` and the CSV report
are the only answers without the envelope.

## The money rule

All money math happens in the API, at full precision, and **only
`InstallmentCalculator::display()` rounds**. It builds the `display` block exactly from the
amount's cents and the stored rate (`rate_scaled`), in the currency's minor units:

- `display.amount_usd`, `display.rate`, `display.converted`, `display.installment`,
  `display.last_installment`, `display.regular_count`, `display.even`, `display.rate_as_of`.
- The installments always add up to `display.converted` exactly; the last one absorbs the
  remainder.

Every front-end (the widget, the terminal bot, a future Typebot flow, your own page) shows these
**display strings verbatim**. Never round, format or recompute the raw numbers (`rate`,
`converted`, `installment_amount`): they are floats for machines, and their last digits can
differ from the display. The bot itself never computes a rate or splits money; its result texts
take only `display.*`, the currency code and the installment count.

Stored money is integer cents (`amount_usd_cents`) and stored rates are integers scaled by 10^10
(`rate_scaled`); no database column holds a float.

## Privacy: the request log and IP blocks

The app records every request it receives, to fight abuse (ADR 0016). Raw IP addresses are
personal data (Colombia's Ley 1581), so this is what is kept, and for how long:

- **The request log.** Every request that reaches the app writes one row in the `requests` table,
  blocked ones included: the raw client IP (the connection's address, or the trusted proxy's
  `X-Forwarded-For` hop when `TRUSTED_PROXY` is set), the method, the path without its query
  string, the status, the latency and whether it was blocked. (A request refused before that,
  while the database can't be used, leaves no row.) Rows are kept `requests.retention_days`
  (30 days) and then deleted by `php api/bin/migrate.php --prune`. **Schedule that job daily**:
  without it nothing is deleted.
- **Rate-limit counters** (`rate_limits`) hold the IP and a per-minute count; `--prune` deletes
  them after a day.
- **Automatic blocks.** An IP over a `block.*` threshold (too many rate-limited minutes, or too
  many other 4xx answers, within `block.window_minutes`) is blocked for
  `block.duration_minutes` (60). Its row in `ip_blocks` holds the raw IP. See every active block,
  with its IP, with `php api/bin/ip.php list`.
- **Manual blocks** (`php api/bin/ip.php block <ip>`) without `--minutes` never expire: they keep
  that IP until an operator removes them with `php api/bin/ip.php unblock <ip>`. `ip.php list`
  counts them separately so they can be reviewed.
- **Ended blocks** (expired or unblocked) are kept one more `block.window_minutes` (10 minutes)
  after their end, so the requests before the block never count again, and then `--prune`
  deletes them.
- **Block events never hold an IP.** `ip.block` and `ip.unblock` events record the source, the
  reason and the duration, never the address and never the operator's reason text. Events are
  never pruned.
- **Reports never show an IP**, nor a path: `/reports` and `bin/report.php` count requests by
  kind, status class and blocked only.

Outside these three tables the app stores no client IP: not in events, sessions, the error log
or the CLI's stderr. Only `ip.php`'s own output shows IPs, because it is the operator's tool. The
bot never stores what a user typed, only the validated values (amount in cents, currency, a
country when one was typed, installments). One thing is outside the app: PHP's built-in server
(`php -S`) prints each request's client address in its own terminal; if you redirect that output
to a file, that file holds IPs too, and no prune touches it.

## Read on

| Document | For |
|---|---|
| [INSTALL_LOCAL.md](INSTALL_LOCAL.md) | Installing, running and operating the app on one machine |
| [REPORTS.md](REPORTS.md) | Reading `/reports` and `bin/report.php` |
| [PLAN.md](PLAN.md) | The build plan: rules, data model, endpoints, phases and tasks |
| [TODO.md](TODO.md) | The current phase and the next task |
| [../CONTEXT.md](../CONTEXT.md) | The glossary: the exact terms used in code and docs |
| [adr/](adr/README.md) | The architecture decisions and their amendments |
| [GUARDRAILS.md](GUARDRAILS.md) | The hard rules and product invariants, and what enforces them |
| [LOG.md](LOG.md) | The dated journal |
| [../CLAUDE.md](../CLAUDE.md) | How changes are made in this repository |
