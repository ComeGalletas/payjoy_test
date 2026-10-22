<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Http\WidgetEndpoint;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WidgetEndpointTest extends TestCase
{
    private const BASE_POLICY = "default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestEnvironment::tempDir('widget');
        file_put_contents($this->directory . '/widget.html', '<!doctype html><title>t</title>');
        file_put_contents($this->directory . '/widget.js', 'console.log(1);');
        file_put_contents($this->directory . '/widget.css', 'p { color: red; }');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function files(): iterable
    {
        yield 'page' => [WidgetEndpoint::PAGE_PATH, '<!doctype html><title>t</title>', 'text/html; charset=utf-8'];
        yield 'script' => [WidgetEndpoint::SCRIPT_PATH, 'console.log(1);', 'text/javascript; charset=utf-8'];
        yield 'style sheet' => [WidgetEndpoint::STYLE_PATH, 'p { color: red; }', 'text/css; charset=utf-8'];
    }

    #[DataProvider('files')]
    public function testEachPathServesItsFileWithItsType(string $path, string $body, string $type): void
    {
        $router = new Router();
        (new WidgetEndpoint($this->directory, []))->register($router);

        $response = $router->dispatch(new Request('GET', $path));

        self::assertSame(200, $response->status());
        self::assertSame($body, $response->body());
        self::assertSame($type, $response->header('content-type'));
        self::assertSame('nosniff', $response->header('x-content-type-options'));
        self::assertSame('no-cache', $response->header('cache-control'));
        self::assertSame('no-referrer', $response->header('referrer-policy'));
        self::assertSame(
            $path === WidgetEndpoint::PAGE_PATH ? self::BASE_POLICY : null,
            $response->header('content-security-policy'),
        );
    }

    public function testOnlyGetIsServedAndNoOtherPathMapsToAFile(): void
    {
        $router = new Router();
        (new WidgetEndpoint($this->directory, []))->register($router);

        foreach ([['POST', '/bot/widget'], ['GET', '/bot/widget/'], ['GET', '/bot/widget.html'], ['GET', '/bot/widget.js/..'], ['GET', '/bot/../bot/widget']] as [$method, $path]) {
            self::assertSame('NOT_FOUND', $router->dispatch(new Request($method, $path))->errorCode(), "{$method} {$path}");
        }
    }

    public function testAMissingFileThrows(): void
    {
        unlink($this->directory . '/widget.css');

        $this->expectException(RuntimeException::class);
        (new WidgetEndpoint($this->directory, []))->serve(WidgetEndpoint::STYLE_PATH);
    }

    public function testAnUnknownPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new WidgetEndpoint($this->directory, []))->serve('/bot/widget.txt');
    }

    public function testTheAllowedOriginsMayFrameThePage(): void
    {
        $response = (new WidgetEndpoint($this->directory, ['https://shop.example', 'http://localhost:8081', 'https://shop.example']))
            ->serve(WidgetEndpoint::PAGE_PATH);

        self::assertSame(
            "default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self' https://shop.example http://localhost:8081",
            $response->header('content-security-policy'),
        );
    }

    #[DataProvider('originsLeftOut')]
    public function testAnOriginThatIsNotPlainIsLeftOutOfFrameAncestors(string $origin): void
    {
        self::assertFalse(WidgetEndpoint::isPlainOrigin($origin));
        self::assertSame(self::BASE_POLICY, WidgetEndpoint::contentSecurityPolicy([$origin]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function originsLeftOut(): iterable
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
        yield 'port zero' => ['https://shop.example:0'];
        yield 'port too large' => ['https://shop.example:65536'];
        yield 'trailing newline' => ["https://shop.example\n"];
        yield 'leading dash label' => ['https://-shop.example'];
    }

    #[DataProvider('plainOrigins')]
    public function testPlainOriginsAreKept(string $origin): void
    {
        self::assertTrue(WidgetEndpoint::isPlainOrigin($origin));
        self::assertSame(self::BASE_POLICY . ' ' . $origin, WidgetEndpoint::contentSecurityPolicy([$origin]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainOrigins(): iterable
    {
        yield 'https host' => ['https://shop.example'];
        yield 'http with port' => ['http://localhost:8081'];
        yield 'highest port' => ['https://shop.example:65535'];
        yield 'IPv4' => ['http://127.0.0.1:8080'];
        yield 'IPv6' => ['http://[::1]:8080'];
        yield 'subdomains and dashes' => ['https://my-shop.eu.example'];
    }
}
