<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\ClientIp;
use FxInstallments\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellingsOfOneAddress(): iterable
    {
        yield 'IPv6 loopback, compressed and full' => ['::1', '0:0:0:0:0:0:0:1'];
        yield 'IPv6 loopback, full and compressed' => ['0:0:0:0:0:0:0:1', '::1'];
        yield 'IPv6, case and zero runs' => ['2001:DB8:0:0::A', '2001:db8::a'];
        yield 'IPv6, leading zeros' => ['2001:0db8::0001', '2001:db8::1'];
        yield 'IPv4 is the same in any spelling PHP accepts' => ['10.0.0.2', '10.0.0.2'];
    }

    /**
     * L-04: TRUSTED_PROXY and REMOTE_ADDR are compared as parsed addresses.
     */
    #[DataProvider('spellingsOfOneAddress')]
    public function testTwoSpellingsOfTheTrustedProxyMatch(string $trustedProxy, string $peer): void
    {
        self::assertSame('203.0.113.50', ClientIp::resolve(self::request($peer, '203.0.113.50'), $trustedProxy));
        self::assertTrue(ClientIp::sameAddress($trustedProxy, $peer));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentOrInvalidAddresses(): iterable
    {
        yield 'different IPv6' => ['::1', '::2'];
        yield 'IPv4 and its IPv4-mapped IPv6 form' => ['10.0.0.2', '::ffff:10.0.0.2'];
        yield 'the same invalid text' => ['proxy', 'proxy'];
        yield 'empty and empty' => ['', ''];
        yield 'a valid proxy and an invalid peer' => ['10.0.0.2', '10.0.0.2 '];
        yield 'an IPv6 zone index' => ['fe80::1%eth0', 'fe80::1%eth0'];
    }

    #[DataProvider('differentOrInvalidAddresses')]
    public function testDifferentAndInvalidValuesNeverMatch(string $trustedProxy, string $peer): void
    {
        self::assertSame($peer, ClientIp::resolve(self::request($peer, '203.0.113.50'), $trustedProxy));
        self::assertFalse(ClientIp::sameAddress($trustedProxy, $peer));
    }

    private static function request(string $peer, ?string $forwardedFor): Request
    {
        return new Request('GET', '/', [], $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor], '', $peer);
    }
}
