<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Metrics\ErrorCodes;

/**
 * Maps an exact method and path to a handler, with an optional middleware chain per route.
 *
 * An unknown path, or a known path with a method it doesn't serve, returns 404 NOT_FOUND.
 * OPTIONS on a path whose route has opted in to Cors is answered by that Cors preflight; other
 * OPTIONS requests are NOT_FOUND too.
 */
final class Router
{
    /** @var array<string, array<string, array{Closure(Request): Response, list<Middleware>}>> path => method => route */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware run in order, outermost first
     */
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        if ($method === 'OPTIONS') {
            throw new \InvalidArgumentException('OPTIONS is answered by the Cors middleware; do not register it');
        }
        $this->routes[$path][$method] = [Closure::fromCallable($handler), array_values($middleware)];
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Every registered route as [method, path], grouped by path in the order each path was first
     * registered (for the INV-04 check).
     *
     * @return list<array{string, string}>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->routes as $path => $byMethod) {
            foreach (array_keys($byMethod) as $method) {
                $routes[] = [$method, (string) $path];
            }
        }

        return $routes;
    }

    public function dispatch(Request $request): Response
    {
        $byMethod = $this->routes[$request->path()] ?? [];

        if ($request->method() === 'OPTIONS') {
            return $this->preflight($request, $byMethod);
        }

        $route = $byMethod[$request->method()] ?? null;
        if ($route === null) {
            return JsonResponse::error(ErrorCodes::NOT_FOUND);
        }
        [$handler, $middleware] = $route;

        $next = $handler;
        foreach (array_reverse($middleware) as $step) {
            $inner = $next;
            $next = static fn (Request $r): Response => $step->process($r, $inner);
        }

        return $next($request);
    }

    /**
     * @param array<string, array{Closure(Request): Response, list<Middleware>}> $byMethod
     */
    private function preflight(Request $request, array $byMethod): Response
    {
        $cors = null;
        $methods = [];
        foreach ($byMethod as $method => [, $middleware]) {
            foreach ($middleware as $step) {
                if ($step instanceof Cors) {
                    $cors ??= $step;
                    $methods[] = $method;
                }
            }
        }

        return $cors === null
            ? JsonResponse::error(ErrorCodes::NOT_FOUND)
            : $cors->preflight($request, $methods);
    }
}
