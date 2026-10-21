<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use RuntimeException;

/**
 * Internal to ExchangeRateClient: a success body with the wrong shape. The reason is a fixed
 * word; the exception is caught inside the client and never leaves it.
 *
 * @internal
 */
final class InvalidShape extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
