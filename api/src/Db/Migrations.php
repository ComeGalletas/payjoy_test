<?php

declare(strict_types=1);

namespace FxInstallments\Db;

/**
 * The ordered migration list: the one place the schema is defined (docs/PLAN.md §5). Migrator
 * applies it, and api/db/schema.sql is generated from it (`php bin/migrate.php --dump`).
 *
 * Rules for this list:
 * - Append only. A migration that has shipped is never edited; a change is a new migration.
 * - Portable SQL (ADR 0010): integer, BIGINT and TEXT columns only, never a floating-point
 *   type (INV-09, ADR 0017); UTC epoch integers; JSON as TEXT; enum values as CHECK
 *   constraints; no SQLite-only functions.
 * - No `--` comments inside the SQL: SQLite keeps the CREATE text verbatim, and the generated
 *   schema.sql is read from it. Comments go here, in PHP.
 */
final class Migrations
{
    /**
     * @return list<Migration> in version order, versions 1, 2, 3, ...
     */
    public static function all(): array
    {
        return [
            // 1: exactly what P1-01 shipped in db/schema.sql (events with a REAL amount_usd,
            // rate_limits). Idempotent, so a P1-01 database, which has no schema_version, runs
            // it as a no-op. app_state does not exist yet, so version 1 is never recorded.
            new Migration(1, 'p1_01_baseline', [
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY,
                    ts INTEGER NOT NULL,
                    source TEXT NOT NULL CHECK (source IN ('api', 'bot', 'upstream', 'llm')),
                    channel TEXT CHECK (channel IS NULL OR channel IN ('static', 'cli', 'typebot')),
                    event_type TEXT NOT NULL,
                    session_id TEXT,
                    step TEXT,
                    currency TEXT,
                    country TEXT,
                    amount_usd REAL,
                    installments INTEGER,
                    status TEXT NOT NULL CHECK (status IN ('ok', 'error')),
                    error_code TEXT,
                    latency_ms INTEGER,
                    meta TEXT
                )
                SQL,
                'CREATE INDEX IF NOT EXISTS events_ts ON events (ts)',
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS rate_limits (
                    ip TEXT NOT NULL,
                    window_start INTEGER NOT NULL,
                    count INTEGER NOT NULL,
                    PRIMARY KEY (ip, window_start)
                )
                SQL,
            ]),

            // 2: the other Phase 1 tables and the indexes --prune and P1-06/P1-11 need.
            // app_state comes first: from here on every migration records schema_version.
            new Migration(2, 'phase1_tables', [
                <<<'SQL'
                CREATE TABLE app_state (
                    key TEXT NOT NULL PRIMARY KEY,
                    value TEXT NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL,
                // One row per currency, all rewritten by one /latest/USD refresh (P1-04).
                // rate_scaled = round(rate * 10^10) (ScaledRate, ADR 0017).
                <<<'SQL'
                CREATE TABLE rate_cache (
                    currency TEXT NOT NULL PRIMARY KEY,
                    rate_scaled BIGINT NOT NULL,
                    last_update_unix INTEGER NOT NULL,
                    next_update_unix INTEGER NOT NULL,
                    fetched_at INTEGER NOT NULL
                )
                SQL,
                // collected is JSON text (P1-06).
                <<<'SQL'
                CREATE TABLE sessions (
                    session_id TEXT NOT NULL PRIMARY KEY,
                    channel TEXT NOT NULL CHECK (channel IN ('static', 'cli', 'typebot')),
                    step TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('open', 'completed', 'abandoned')),
                    collected TEXT NOT NULL DEFAULT '{}',
                    retries INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                )
                SQL,
                // The P1-06 expiry sweep: WHERE status = 'open' AND expires_at <= ? (equality,
                // then range). --prune filters on status too.
                'CREATE INDEX sessions_status_expires_at ON sessions (status, expires_at)',
                // One row per request (ADR 0016, P1-11). path is at most 200 characters and never
                // has the query string; blocked is 0 or 1.
                <<<'SQL'
                CREATE TABLE requests (
                    id INTEGER PRIMARY KEY,
                    ts INTEGER NOT NULL,
                    ip TEXT NOT NULL,
                    method TEXT NOT NULL,
                    path TEXT NOT NULL,
                    status INTEGER NOT NULL,
                    latency_ms INTEGER,
                    blocked INTEGER NOT NULL DEFAULT 0 CHECK (blocked IN (0, 1))
                )
                SQL,
                'CREATE INDEX requests_ts ON requests (ts)',
                'CREATE INDEX requests_ip_ts ON requests (ip, ts)',
                // One block per IP. Only a manual block may have no expiry (ADR 0016).
                <<<'SQL'
                CREATE TABLE ip_blocks (
                    ip TEXT NOT NULL PRIMARY KEY,
                    reason TEXT NOT NULL,
                    source TEXT NOT NULL CHECK (source IN ('auto', 'manual')),
                    blocked_at INTEGER NOT NULL,
                    expires_at INTEGER,
                    CHECK (expires_at IS NOT NULL OR source = 'manual')
                )
                SQL,
                'CREATE INDEX rate_limits_window_start ON rate_limits (window_start)',
            ]),

            // 3: events.amount_usd REAL becomes amount_usd_cents INTEGER (ADR 0017). SQLite
            // can't change a column's type in place, so the table is rebuilt: create, copy,
            // drop, rename, all in the migration's one transaction. Existing amounts become
            // round(x * 100) cents (ROUND is half away from zero on SQLite); NULL stays NULL.
            new Migration(3, 'events_amount_usd_cents', [
                <<<'SQL'
                CREATE TABLE events_new (
                    id INTEGER PRIMARY KEY,
                    ts INTEGER NOT NULL,
                    source TEXT NOT NULL CHECK (source IN ('api', 'bot', 'upstream', 'llm')),
                    channel TEXT CHECK (channel IS NULL OR channel IN ('static', 'cli', 'typebot')),
                    event_type TEXT NOT NULL,
                    session_id TEXT,
                    step TEXT,
                    currency TEXT,
                    country TEXT,
                    amount_usd_cents INTEGER,
                    installments INTEGER,
                    status TEXT NOT NULL CHECK (status IN ('ok', 'error')),
                    error_code TEXT,
                    latency_ms INTEGER,
                    meta TEXT
                )
                SQL,
                <<<'SQL'
                INSERT INTO events_new (id, ts, source, channel, event_type, session_id, step, currency,
                    country, amount_usd_cents, installments, status, error_code, latency_ms, meta)
                SELECT id, ts, source, channel, event_type, session_id, step, currency,
                    country, CAST(ROUND(amount_usd * 100) AS INTEGER), installments, status, error_code,
                    latency_ms, meta
                FROM events
                SQL,
                'DROP TABLE events',
                'ALTER TABLE events_new RENAME TO events',
                'CREATE INDEX events_ts ON events (ts)',
            ]),
        ];
    }

    public static function latestVersion(): int
    {
        $all = self::all();

        return $all[count($all) - 1]->version;
    }
}
