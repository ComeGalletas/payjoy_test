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
        // L-04 cleanup B (owner decision, ADR 0016 fifth amendment): an IPv4-mapped address is
        // its IPv4 address, in either argument, as it is to IpBlocking::isLoopback.
        yield 'IPv4 proxy, IPv4-mapped peer' => ['10.0.0.2', '::ffff:10.0.0.2'];
        yield 'IPv4-mapped proxy, IPv4 peer' => ['::ffff:10.0.0.2', '10.0.0.2'];
        yield 'IPv4 proxy, IPv4-mapped peer in hex' => ['10.0.0.2', '::ffff:a00:2'];
        yield 'IPv4 proxy, IPv4-mapped peer in full' => ['10.0.0.2', '0:0:0:0:0:FFFF:10.0.0.2'];
        yield 'IPv4-mapped on both sides' => ['::ffff:10.0.0.2', '::FFFF:a00:2'];
    }

    /**
     * L-04: TRUSTED_PROXY and REMOTE_ADDR are compared as parsed addresses, an IPv4-mapped
     * address as its IPv4 address (cleanup B).
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
        yield 'the same invalid text' => ['proxy', 'proxy'];
        yield 'empty and empty' => ['', ''];
        yield 'a valid proxy and an invalid peer' => ['10.0.0.2', '10.0.0.2 '];
        yield 'an IPv6 zone index' => ['fe80::1%eth0', 'fe80::1%eth0'];
        // L-04 cleanup B: unmapping matches only the same IPv4 address, and only valid values.
        yield 'another IPv4 address, mapped' => ['10.0.0.2', '::ffff:10.0.0.3'];
        yield 'another IPv4 address, mapped proxy' => ['::ffff:10.0.0.3', '10.0.0.2'];
        yield 'IPv4-compatible, not mapped' => ['10.0.0.2', '::10.0.0.2'];
        yield 'mapped with a zone index' => ['10.0.0.2', '::ffff:10.0.0.2%eth0'];
        yield 'mapped in brackets' => ['10.0.0.2', '[::ffff:10.0.0.2]'];
        yield 'mapped in brackets with a port' => ['10.0.0.2', '[::ffff:10.0.0.2]:8080'];
        yield 'IPv4 with a port' => ['::ffff:10.0.0.2', '10.0.0.2:8080'];
        yield 'mapped, invalid IPv4 part' => ['10.0.0.2', '::ffff:10.0.0.256'];
        yield 'the same invalid mapped text' => ['::ffff:proxy', '::ffff:proxy'];
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
