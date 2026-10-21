<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\Cors;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    private const ALLOWED = 'https://shop.example';

    public function testPreflightFromAnAllowedOriginGetsTheCorsHeaders(): void
    {
        $response = (new Cors([self::ALLOWED]))->preflight(self::preflight(self::ALLOWED, 'post'), ['POST']);

        self::assertSame(200, $response->status());
        self::assertSame('{"ok":true,"data":{}}', $response->body());
        self::assertSame(self::ALLOWED, $response->header('Access-Control-Allow-Origin'));
        self::assertSame('POST', $response->header('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->header('Access-Control-Max-Age'));
        self::assertSame('Origin', $response->header('Vary'));
    }

    public function testPreflightFromADisallowedOriginGetsNoCorsHeaders(): void
    {
        $cors = new Cors([self::ALLOWED]);

        foreach (['https://evil.example', 'http://shop.example', 'https://shop.example:8443', 'https://shop.example.evil.example', 'null'] as $origin) {
            self::assertNoCorsHeaders($cors->preflight(self::preflight($origin, 'POST'), ['POST']), $origin);
        }
    }

    public function testPreflightForAMethodTheRouteDoesNotServeGetsNoCorsHeaders(): void
    {
        self::assertNoCorsHeaders((new Cors([self::ALLOWED]))->preflight(self::preflight(self::ALLOWED, 'DELETE'), ['POST']), 'DELETE');
    }

    public function testTheLiteralNullOriginCannotBeAllowed(): void
    {
        self::assertNoCorsHeaders((new Cors(['null', '']))->preflight(self::preflight('null', 'POST'), ['POST']), 'null');
    }

    public function testAnAllowedRequestGetsTheOriginHeader(): void
    {
        $response = (new Cors([self::ALLOWED]))->process(
            new Request('POST', '/bot/message', [], ['Origin' => self::ALLOWED]),
            static fn (): JsonResponse => JsonResponse::ok(['reply' => 'hi']),
        );

        self::assertSame('{"ok":true,"data":{"reply":"hi"}}', $response->body());
        self::assertSame(self::ALLOWED, $response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->header('Vary'));
    }

    public function testADisallowedOrSameOriginRequestGetsNoOriginHeader(): void
    {
        $cors = new Cors([self::ALLOWED]);
        foreach ([['Origin' => 'https://evil.example'], []] as $headers) {
            $response = $cors->process(new Request('POST', '/bot/message', [], $headers), static fn (): JsonResponse => JsonResponse::ok());
            self::assertNull($response->header('Access-Control-Allow-Origin'));
        }
    }

    public function testTheDefaultConfigAllowsNoCrossOrigin(): void
    {
        $cors = TestEnvironment::app()->cors();

        self::assertNoCorsHeaders($cors->preflight(self::preflight(self::ALLOWED, 'POST'), ['POST']), 'default');
    }

    private static function preflight(string $origin, string $method): Request
    {
        return new Request('OPTIONS', '/bot/message', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => $method,
        ]);
    }

    private static function assertNoCorsHeaders(JsonResponse $response, string $case): void
    {
        foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers'] as $header) {
            self::assertNull($response->header($header), "{$header} must not be sent ({$case})");
        }
        self::assertSame('{"ok":true,"data":{}}', $response->body());
    }
}
