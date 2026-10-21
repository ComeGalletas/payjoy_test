<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use FxInstallments\Http\Cors;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Middleware;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesByExactMethodAndPath(): void
    {
        $router = new Router();
        $router->get('/a', static fn (): JsonResponse => JsonResponse::ok(['route' => 'get a']));
        $router->post('/a', static fn (): JsonResponse => JsonResponse::ok(['route' => 'post a']));

        self::assertSame('{"ok":true,"data":{"route":"get a"}}', $router->dispatch(new Request('GET', '/a'))->body());
        self::assertSame('{"ok":true,"data":{"route":"post a"}}', $router->dispatch(new Request('POST', '/a'))->body());
    }

    public function testUnknownPathWrongMethodAndTrailingSlashAreNotFound(): void
    {
        $router = new Router();
        $router->get('/a', static fn (): JsonResponse => JsonResponse::ok());

        foreach ([new Request('GET', '/b'), new Request('DELETE', '/a'), new Request('GET', '/a/'), new Request('HEAD', '/a')] as $request) {
            $response = $router->dispatch($request);
            self::assertSame(404, $response->status());
            self::assertSame('NOT_FOUND', $response->errorCode());
        }
    }

    public function testMiddlewareRunsInOrderAroundTheHandler(): void
    {
        $trace = new ArrayObject();
        $step = static fn (string $name): Middleware => new class ($name, $trace) implements Middleware {
            /** @param ArrayObject<int, string> $trace */
            public function __construct(private readonly string $name, private readonly ArrayObject $trace)
            {
            }

            public function process(Request $request, callable $next): JsonResponse
            {
                $this->trace[] = "{$this->name} in";
                $response = $next($request);
                $this->trace[] = "{$this->name} out";

                return $response;
            }
        };

        $router = new Router();
        $router->get('/a', static function () use ($trace): JsonResponse {
            $trace[] = 'handler';

            return JsonResponse::ok();
        }, [$step('outer'), $step('inner')]);
        $router->dispatch(new Request('GET', '/a'));

        self::assertSame(['outer in', 'inner in', 'handler', 'inner out', 'outer out'], $trace->getArrayCopy());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $deny = new class implements Middleware {
            public function process(Request $request, callable $next): JsonResponse
            {
                return JsonResponse::error('ADMIN_UNAUTHORIZED');
            }
        };
        $called = false;
        $router = new Router();
        $router->get('/a', static function () use (&$called): JsonResponse {
            $called = true;

            return JsonResponse::ok();
        }, [$deny]);

        self::assertSame(401, $router->dispatch(new Request('GET', '/a'))->status());
        self::assertFalse($called);
    }

    public function testOptionsIsNotFoundUnlessTheRouteOptsInToCors(): void
    {
        $router = new Router();
        $router->get('/plain', static fn (): JsonResponse => JsonResponse::ok());
        $router->post('/cors', static fn (): JsonResponse => JsonResponse::ok(), [new Cors(['https://shop.example'])]);

        self::assertSame(404, $router->dispatch(new Request('OPTIONS', '/plain'))->status());
        self::assertSame(404, $router->dispatch(new Request('OPTIONS', '/missing'))->status());

        $preflight = $router->dispatch(new Request('OPTIONS', '/cors', [], [
            'Origin' => 'https://shop.example',
            'Access-Control-Request-Method' => 'POST',
        ]));
        self::assertSame(200, $preflight->status());
        self::assertSame('https://shop.example', $preflight->header('Access-Control-Allow-Origin'));
        self::assertSame('POST', $preflight->header('Access-Control-Allow-Methods'));
    }

    public function testOptionsRoutesCannotBeRegistered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->add('options', '/a', static fn (): JsonResponse => JsonResponse::ok());
    }
}
