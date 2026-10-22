<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

/**
 * What the admin `/health` view shows about the upstream quota (P1-08): where the answer came
 * from, when upstream was last asked, and either the quota or the error code of that ask.
 *
 * - `cache`: the stored answer is younger than `health.quota_cache_minutes`;
 * - `upstream`: upstream was just asked (the answer may be an error);
 * - `blocked`: not asked, because the breaker holds an auth block (the key is known bad); the
 *   last stored answer, if any, is kept for reference.
 */
final class QuotaStatus
{
    public const CACHE = 'cache';
    public const UPSTREAM = 'upstream';
    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $source,
        public readonly ?int $checkedAt,
        public readonly ?Quota $quota,
        public readonly ?string $errorCode,
    ) {
    }
}
