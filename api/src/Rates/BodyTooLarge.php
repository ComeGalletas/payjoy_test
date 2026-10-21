<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use RuntimeException;

/**
 * Internal to ExchangeRateClient: a response passed MAX_BODY_BYTES during the transfer. It is
 * caught inside the client and never leaves it.
 *
 * @internal
 */
final class BodyTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('body too large');
    }
}
