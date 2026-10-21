-- FX Installments schema (docs/PLAN.md §5). Portable SQL only (ADR 0010): UTC epoch integers,
-- JSON as TEXT, no SQLite-only functions in queries.
--
-- P1-01 ships only the two tables its code needs, events (Metrics) and rate_limits
-- (RateLimiter). P1-02 adds rate_cache, sessions and app_state, bin/migrate.php and --prune.
-- Every statement must be idempotent (IF NOT EXISTS) and end with ';' at the end of a line.

-- Append-only source of truth for metrics; amounts unrounded; meta is JSON text.
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
);

CREATE INDEX IF NOT EXISTS events_ts ON events (ts);

-- Per-IP request counts per fixed one-minute window (window_start is a UTC epoch second).
CREATE TABLE IF NOT EXISTS rate_limits (
    ip TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (ip, window_start)
);
