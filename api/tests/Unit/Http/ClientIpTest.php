<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\ClientIp;
use FxInstallments\Http\Request;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testForwardedForIsIgnoredWithoutATrustedProxy(): void
    {
        $request = self::request('198.51.100.9', '203.0.113.50');

        self::assertSame('198.51.100.9', ClientIp::resolve($request, null));
        self::assertSame('198.51.100.9', ClientIp::resolve($request, ''));
    }

    public function testForwardedForIsIgnoredWhenThePeerIsNotTheTrustedProxy(): void
    {
        self::assertSame('198.51.100.9', ClientIp::resolve(self::request('198.51.100.9', '203.0.113.50'), '10.0.0.2'));
    }

    public function testTheTrustedProxyHopIsUsed(): void
    {
        // The client wrote the first entry itself; the proxy appended the last one.
        $request = self::request('10.0.0.2', '1.2.3.4, 203.0.113.50');

        self::assertSame('203.0.113.50', ClientIp::resolve($request, '10.0.0.2'));
    }

    public function testAnInvalidOrMissingHopFallsBackToThePeer(): void
    {
        self::assertSame('10.0.0.2', ClientIp::resolve(self::request('10.0.0.2', 'not-an-ip'), '10.0.0.2'));
        self::assertSame('10.0.0.2', ClientIp::resolve(self::request('10.0.0.2', '203.0.113.50, '), '10.0.0.2'));
        self::assertSame('10.0.0.2', ClientIp::resolve(self::request('10.0.0.2', null), '10.0.0.2'));
    }

    public function testIpv6HopsAreAccepted(): void
    {
        self::assertSame('2001:db8::1', ClientIp::resolve(self::request('::1', '2001:db8::1'), '::1'));
    }

    private static function request(string $peer, ?string $forwardedFor): Request
    {
        return new Request('GET', '/', [], $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor], '', $peer);
    }
}
