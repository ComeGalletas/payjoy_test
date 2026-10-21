<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Fixtures\Network;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Not part of any suite. RealGuzzleHandlerGuardTest runs it in a separate PHPUnit process to
 * show that a test which builds a real Guzzle handler and swallows the failure still fails the
 * run (the guard exits 1 at shutdown).
 */
final class SwallowedRealHandlerProbe extends TestCase
{
    public function testBuildsARealClientAndSwallowsTheFailure(): void
    {
        try {
            new Client();
        } catch (Throwable) {
            // Deliberately swallowed.
        }
        self::assertTrue(true);
    }
}
