<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\AllowedOrigins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllowedOriginsTest extends TestCase
{
    #[DataProvider('invalid')]
    public function testAnEntryABrowserWouldNeverSendIsInvalid(string $origin): void
    {
        self::assertFalse(AllowedOrigins::isValid($origin));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => ['null'];
        yield 'wildcard' => ['*'];
        yield 'wildcard host' => ['https://*.example'];
        yield 'directive injection' => ["https://shop.example; script-src 'unsafe-inline'"];
        yield 'space-separated list' => ['https://a.example https://b.example'];
        yield 'keyword' => ["'unsafe-inline'"];
        yield 'trailing slash' => ['https://shop.example/'];
        yield 'path' => ['https://shop.example/app'];
        yield 'query' => ['https://shop.example?x=1'];
        yield 'credentials' => ['https://user@shop.example'];
        yield 'other scheme' => ['ftp://shop.example'];
        yield 'data scheme' => ['data:'];
        yield 'no scheme' => ['shop.example'];
        yield 'uppercase host' => ['https://Shop.example'];
        yield 'uppercase scheme' => ['HTTPS://shop.example'];
        yield 'default https port' => ['https://shop.example:443'];
        yield 'default http port' => ['http://shop.example:80'];
        yield 'port zero' => ['https://shop.example:0'];
        yield 'leading zero port' => ['https://shop.example:08080'];
        yield 'port too large' => ['https://shop.example:65536'];
        yield 'trailing newline' => ["https://shop.example\n"];
        yield 'leading space' => [' https://shop.example'];
        yield 'leading dash label' => ['https://-shop.example'];
        yield 'empty label' => ['https://shop..example'];
    }

    #[DataProvider('valid')]
    public function testAnExactOriginIsValid(string $origin): void
    {
        self::assertTrue(AllowedOrigins::isValid($origin));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'https host' => ['https://shop.example'];
        yield 'http with port' => ['http://localhost:8081'];
        yield 'highest port' => ['https://shop.example:65535'];
        yield 'https on port 80' => ['https://shop.example:80'];
        yield 'IPv4' => ['http://127.0.0.1:8080'];
        yield 'IPv6' => ['http://[::1]:8080'];
        yield 'subdomains and dashes' => ['https://my-shop.eu.example'];
    }

    public function testFromConfigKeepsValidEntriesOnceAndCountsTheRest(): void
    {
        $origins = AllowedOrigins::fromConfig(['https://shop.example', '*', 'https://shop.example', 'HTTPS://SHOP.EXAMPLE', 'http://localhost:8081', 'https://shop.example/']);

        self::assertSame(['https://shop.example', 'http://localhost:8081'], $origins->origins);
        self::assertSame(3, $origins->dropped);
    }

    public function testAnEmptyListDropsNothing(): void
    {
        $origins = AllowedOrigins::fromConfig([]);

        self::assertSame([], $origins->origins);
        self::assertSame(0, $origins->dropped);
    }
}
