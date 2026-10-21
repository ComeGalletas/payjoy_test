<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

/**
 * A settable clock returning UTC epoch seconds.
 */
final class FakeClock
{
    public function __construct(public int $now)
    {
    }

    public function __invoke(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
