<?php

declare(strict_types=1);

/*
 * Single entry point. Works as the front controller of a web server or as the router script of
 * PHP's built-in server:
 *
 *     php -S 127.0.0.1:8080 api/public/index.php
 *
 * Errors are never displayed: a failure anywhere, even before the app is wired, answers with
 * the INTERNAL envelope and no detail.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$apiRoot = dirname(__DIR__);

try {
    require $apiRoot . '/vendor/autoload.php';

    FxInstallments\Bootstrap::init($apiRoot);
    FxInstallments\App::fromEnvironment($apiRoot)
        ->kernel()
        ->handle(FxInstallments\Http\Request::fromGlobals())
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
