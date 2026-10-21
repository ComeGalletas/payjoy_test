<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * What IpBlocks::blockAutomatically() did: started a block, extended an active automatic one,
 * or left the row unchanged (an active manual block, or an automatic one that already ends
 * later). Only the first two write an `ip.block` event.
 */
final class AutoBlockOutcome
{
    public const STARTED = 'started';
    public const EXTENDED = 'extended';
    public const UNCHANGED = 'unchanged';

    public function __construct(public readonly IpBlock $block, public readonly string $outcome)
    {
    }

    public function changed(): bool
    {
        return $this->outcome !== self::UNCHANGED;
    }
}
