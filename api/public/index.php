<?php

declare(strict_types=1);

/*
 * Single entry point. Works as the front controller of a web server or as the router script of
 * PHP's built-in server:
 *
 *     php -S 127.0.0.1:8080 api/public/index.php
 *
 * Errors are never displayed: a failure anywhere, even before the app is wired, answers with
 * the INTERNAL envelope and no detail. That includes a database that can't be opened, a
 * missing SQLite file (DatabaseFileMissing: refused, never created, L-04) or a schema behind the
 * code (Migrator::assertCurrent), except on `GET /health`, which answers 503 UNHEALTHY for it
 * (P1-08).
 *
 * No response carries `X-Powered-By` (P1-07). PHP adds `X-Powered-By: PHP/<version>` before
 * this script runs when `expose_php` is on; that setting can only be changed in php.ini, so the
 * header is removed here, first, for every response including the fallback below. The app
 * itself never sets one. Setting `expose_php = Off` in php.ini as well is recommended.
 */

header_remove('X-Powered-By');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$apiRoot = dirname(__DIR__);

try {
    require $apiRoot . '/vendor/autoload.php';

    FxInstallments\Bootstrap::init($apiRoot);
    $app = FxInstallments\App::fromEnvironment($apiRoot);
    $request = FxInstallments\Http\Request::fromGlobals();
    // Open the database and check its schema version before routing: a missing SQLite file or
    // a database behind the code (run php api/bin/migrate.php) fails closed with INTERNAL below. `GET /health` is the one
    // exception (P1-08): it reports an unusable database itself, as 503 UNHEALTHY. Any other
    // method on /health fails closed too, instead of reaching the router's counted 404.
    if ($request->method() !== 'GET' || $request->path() !== FxInstallments\Http\HealthEndpoint::PATH) {
        $app->db->pdo();
    }
    $app->kernel()
        ->handle($request)
        ->send();
} catch (Throwable $e) {
    error_log(sprintf('fx-installments INTERNAL before routing: %s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
    }
    echo '{"ok":false,"error":{"code":"INTERNAL","message_for_user":"Something went wrong on our side. Please try again later."}}';
}
