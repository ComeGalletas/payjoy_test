<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use Throwable;

/**
 * Runs a request through the router and turns every outcome into an envelope.
 *
 * - Any uncaught Throwable becomes 500 INTERNAL with the generic message. The exception's
 *   message and trace never reach the response or the event; the error log gets its class,
 *   file and line only.
 * - Every error response is recorded as an `error` event with its code, HTTP status, method,
 *   path (never the query string) and latency. Metrics never throws (INV-07).
 */
final class Kernel
{
    private const MAX_PATH_IN_EVENT = 200;

    /** @var Closure(string): void */
    private readonly Closure $errorLog;

    /**
     * @param (Closure(string): void)|null $errorLog defaults to error_log()
     */
    public function __construct(
        private readonly Router $router,
        private readonly Metrics $metrics,
        ?Closure $errorLog = null,
    ) {
        $this->errorLog = $errorLog ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function handle(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $meta = [];
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $e) {
            $response = JsonResponse::error(ErrorCodes::INTERNAL);
            $meta['exception'] = $e::class;
            $this->logInternal($e);
        }

        $errorCode = $response->errorCode();
        if ($errorCode !== null) {
            $this->metrics->error($errorCode, [
                'latency_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'meta' => [
                    'http_status' => $response->status(),
                    'method' => substr($request->method(), 0, 16),
                    'path' => substr($request->path(), 0, self::MAX_PATH_IN_EVENT),
                ] + $meta,
            ]);
        }

        return $response;
    }

    private function logInternal(Throwable $e): void
    {
        try {
            ($this->errorLog)(sprintf(
                'fx-installments INTERNAL: %s at %s:%d',
                $e::class,
                $e->getFile(),
                $e->getLine(),
            ));
        } catch (Throwable) {
            // Logging must not turn a 500 into a broken response.
        }
    }
}
