<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use FxInstallments\App;
use FxInstallments\Env;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Router;
use RuntimeException;

/**
 * Test-only route table: the production middleware from App on placeholder handlers, so the
 * foundation can be exercised before any real endpoint exists. Never used by public/index.php.
 */
final class TestRoutes
{
    public const ADMIN = '/test/admin';
    public const LIMITED = '/test/limited';
    public const RECORD = '/test/record';
    public const BOOM = '/test/boom';
    public const CORS = '/test/cors';

    public static function router(App $app): Router
    {
        $router = new Router();

        $router->get(self::ADMIN, static fn (): JsonResponse => JsonResponse::ok(['admin' => true]), [$app->adminAuth()]);

        $router->get(self::LIMITED, static fn (): JsonResponse => JsonResponse::ok(['limited' => true]), [$app->rateLimiter()]);

        $router->get(self::RECORD, static function () use ($app): JsonResponse {
            $recorded = $app->metrics->record('api.request', ['meta' => ['route' => self::RECORD]]);

            return JsonResponse::ok(['recorded' => $recorded]);
        });

        $router->get(self::BOOM, static function (): JsonResponse {
            throw new RuntimeException('secret detail: key=' . Env::get('EXCHANGERATE_API_KEY') . ' at C:\\secret\\path');
        });

        $router->post(self::CORS, static fn (): JsonResponse => JsonResponse::ok(['cors' => true]), [$app->cors()]);

        return $router;
    }
}
