<?php

declare(strict_types=1);

/*
 * Test-only replacements for Guzzle's three real transport handlers (INV-06, ADR 0018).
 *
 * NetworkGuard::install() registers an autoloader, ahead of Composer's, that loads this file
 * the first time any of these classes is needed, so in the test process they are these stubs
 * and never Guzzle's own. Building one (which `new Client()` or `HandlerStack::create()`
 * without a handler does, through `Utils::chooseHandler()`) records an attempt and throws, and
 * the guard fails the run at shutdown even if the exception was caught. MockHandler is a
 * different class and is untouched.
 *
 * The file is named in snake case so Composer's PSR-4 autoloader never maps a class to it.
 */

namespace GuzzleHttp\Handler;

use FxInstallments\Tests\Support\NetworkGuard;

final class CurlHandler
{
    public function __construct(mixed ...$arguments)
    {
        NetworkGuard::refuseRealHandler(self::class);
    }

    public function __invoke(mixed ...$arguments): never
    {
        NetworkGuard::refuseRealHandler(self::class);
    }
}

final class CurlMultiHandler
{
    public function __construct(mixed ...$arguments)
    {
        NetworkGuard::refuseRealHandler(self::class);
    }

    public function __invoke(mixed ...$arguments): never
    {
        NetworkGuard::refuseRealHandler(self::class);
    }
}

final class StreamHandler
{
    public function __construct(mixed ...$arguments)
    {
        NetworkGuard::refuseRealHandler(self::class);
    }

    public function __invoke(mixed ...$arguments): never
    {
        NetworkGuard::refuseRealHandler(self::class);
    }
}
