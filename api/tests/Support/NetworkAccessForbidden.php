<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use LogicException;

/**
 * Thrown when a test opens a network stream (INV-06).
 */
final class NetworkAccessForbidden extends LogicException
{
}
