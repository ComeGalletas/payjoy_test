<?php

declare(strict_types=1);

/*
 * Read-only config defaults for Phases 1-2 (docs/PLAN.md §6, ADR 0014). Changing a setting
 * means editing this file and redeploying; Phase 3 adds the config API.
 */

return [
    'installments.default' => 1,
    'installments.max' => 60,
    'amount.max' => 1000000,
    'bot.max_retries' => 3,
    'bot.session_ttl_minutes' => 30,
    'bot.frontend' => 'static',
    'rates.max_stale_hours' => 48,
    'ratelimit.per_minute' => 30,
    'cors.allowed_origins' => [],
    'reports.timezone' => 'America/Bogota',
    'health.quota_cache_minutes' => 60,
    'llm.enabled' => false,
    'llm.confidence_threshold' => 0.8,
];
