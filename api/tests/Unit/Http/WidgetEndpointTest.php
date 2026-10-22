<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\NoContentResponse;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Http\WidgetEndpoint;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WidgetEndpointTest extends TestCase
{
    private const POLICY_HEAD = "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    private const POLICY_TAIL = "; require-trusted-types-for 'script'; trusted-types 'none'";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestEnvironment::tempDir('widget');
        file_put_contents($this->directory . '/widget.html', '<!doctype html><title>t</title>');
        file_put_contents($this->directory . '/widget.js', 'console.log(1);');
        file_put_contents($this->directory . '/widget.css', 'p { color: red; }');
        file_put_contents($this->directory . '/widget.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function files(): iterable
    {
        yield 'page' => [WidgetEndpoint::PAGE_PATH, '<!doctype html><title>t</title>', 'text/html; charset=utf-8', self::POLICY_HEAD . self::POLICY_TAIL];
        yield 'script' => [WidgetEndpoint::SCRIPT_PATH, 'console.log(1);', 'text/javascript; charset=utf-8', null];
        yield 'style sheet' => [WidgetEndpoint::STYLE_PATH, 'p { color: red; }', 'text/css; charset=utf-8', null];
        yield 'icon' => [WidgetEndpoint::ICON_PATH, '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', "default-src 'none'"];
    }

    #[DataProvider('files')]
    public function testEachPathServesItsFileWithItsType(string $path, string $body, string $type, ?string $policy): void
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
        self::assertSame($policy, $response->header('content-security-policy'));
    }

    public function testFaviconIsA204WithNoBodyOrType(): void
    {
        $router = new Router();
        (new WidgetEndpoint($this->directory, []))->register($router);

        $response = $router->dispatch(new Request('GET', WidgetEndpoint::FAVICON_PATH));

        self::assertInstanceOf(NoContentResponse::class, $response);
        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertNull($response->errorCode());
        self::assertSame(['x-content-type-options' => 'nosniff', 'cache-control' => 'max-age=86400'], $response->headers());
        self::assertArrayNotHasKey('content-type', $response->withHeader('Content-Type', 'text/plain')->headers());
    }

    public function testRegisterAddsExactlyTheListedPaths(): void
    {
        $router = new Router();
        (new WidgetEndpoint($this->directory, []))->register($router);

        self::assertSame(
            [['GET', '/bot/widget'], ['GET', '/bot/widget.js'], ['GET', '/bot/widget.css'], ['GET', '/bot/widget.svg'], ['GET', '/favicon.ico']],
            $router->routes(),
        );
        self::assertSame(array_column($router->routes(), 1), WidgetEndpoint::paths());
    }

    public function testOnlyGetIsServedAndNoOtherPathMapsToAFile(): void
    {
        $router = new Router();
        (new WidgetEndpoint($this->directory, []))->register($router);

        foreach ([['POST', '/bot/widget'], ['GET', '/bot/widget/'], ['GET', '/bot/widget.html'], ['GET', '/bot/widget.js/..'], ['GET', '/bot/../bot/widget'], ['POST', '/favicon.ico'], ['GET', '/bot/favicon.ico']] as [$method, $path]) {
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
        (new WidgetEndpoint($this->directory, []))->serve(WidgetEndpoint::FAVICON_PATH);
    }

    public function testTheAllowedOriginsMayFrameThePage(): void
    {
        $response = (new WidgetEndpoint($this->directory, ['https://shop.example', 'http://localhost:8081', 'https://shop.example']))
            ->serve(WidgetEndpoint::PAGE_PATH);

        self::assertSame(
            self::POLICY_HEAD . ' https://shop.example http://localhost:8081' . self::POLICY_TAIL,
            $response->header('content-security-policy'),
        );
    }

    /**
     * Even an unfiltered list can't add a directive: the endpoint checks each origin again.
     */
    public function testAnInvalidOriginIsLeftOutOfFrameAncestors(): void
    {
        self::assertSame(
            self::POLICY_HEAD . self::POLICY_TAIL,
            WidgetEndpoint::contentSecurityPolicy(['*', "https://shop.example; script-src 'unsafe-inline'", 'HTTPS://SHOP.EXAMPLE', "'self'"]),
        );
    }
}
