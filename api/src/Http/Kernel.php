<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\RequestLog;
use Throwable;

/**
 * Runs a request through the block check and the router, turns every outcome into an
 * envelope, and records it.
 *
 * - **Block check** (ADR 0016), before routing and so before any middleware or CORS preflight:
 *   a client IP with an active IP block gets a minimal 403 IP_BLOCKED envelope with
 *   `Connection: close`. No handler runs and no `error` event is written; its request-log row
 *   (`blocked = 1`) is the only write. If the check itself can't read the database, the request
 *   is let through (fail open) and a note goes to the error log.
 * - Any uncaught Throwable becomes 500 INTERNAL with the generic message. The exception's
 *   message and trace never reach the response or the event; the error log gets its class,
 *   file and line only.
 * - Every error response that reached routing is recorded as an `error` event with its code,
 *   HTTP status, method, path and latency. Method and path follow the request log's rules
 *   (RequestLog::storedMethod(), storedPath()): no query string or fragment, ASCII only, cut
 *   without splitting an escape. Metrics never throws (INV-07).
 * - **Request log:** every request handled here, blocked ones included, writes exactly one
 *   `requests` row with its final status and latency (time until the response was built,
 *   block check included). A failed write never fails the request.
 * - **Automatic blocking:** after the row is written, IpBlocking may block the IP for its next
 *   requests. A failure there is noted and never fails the request.
 *
 * The request log and blocking are optional so the router can be tested alone; App::kernel()
 * always wires both.
 */
final class Kernel
{
    /** @var Closure(string): void */
    private readonly Closure $errorLog;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(string): void)|null $errorLog defaults to error_log()
     * @param (Closure(): int)|null $clock UTC epoch seconds for the request log and blocks; defaults to time()
     * @param string|null $trustedProxy TRUSTED_PROXY, for the client IP (ClientIp)
     */
    public function __construct(
        private readonly Router $router,
        private readonly Metrics $metrics,
        ?Closure $errorLog = null,
        private readonly ?RequestLog $requestLog = null,
        private readonly ?IpBlocking $blocking = null,
        ?Closure $clock = null,
        private readonly ?string $trustedProxy = null,
    ) {
        $this->errorLog = $errorLog ?? static function (string $message): void {
            error_log($message);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function handle(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $now = ($this->clock)();
        $ip = ClientIp::resolve($request, $this->trustedProxy);

        $block = $this->checkBlock($ip);
        if ($block !== null && $block->isActiveAt($now)) {
            $response = JsonResponse::error(ErrorCodes::IP_BLOCKED)->withHeader('Connection', 'close');
            $this->requestLog?->record($now, $ip, $request->method(), $request->path(), $response->status(), self::elapsedMs($started), true);

            return $response;
        }

        $meta = [];
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $e) {
            $response = JsonResponse::error(ErrorCodes::INTERNAL);
            $meta['exception'] = $e::class;
            $this->logInternal($e);
        }

        $latencyMs = self::elapsedMs($started);
        $errorCode = $response->errorCode();
        if ($errorCode !== null) {
            $this->metrics->error($errorCode, [
                'latency_ms' => $latencyMs,
                'meta' => [
                    'http_status' => $response->status(),
                    'method' => RequestLog::storedMethod($request->method()),
                    'path' => RequestLog::storedPath($request->path()),
                ] + $meta,
            ]);
        }

        $this->requestLog?->record($now, $ip, $request->method(), $request->path(), $response->status(), $latencyMs, false);
        $this->applyAutomaticBlock($ip, $response->status(), $now, $block);

        return $response;
    }

    /**
     * The IP's block row, or null when it has none or the lookup failed (fail open).
     */
    private function checkBlock(string $ip): ?IpBlock
    {
        if ($this->blocking === null) {
            return null;
        }
        try {
            return $this->blocking->lookup($ip);
        } catch (Throwable $e) {
            $this->note(sprintf('fx-installments block check failed, request let through (%s)', $e::class));

            return null;
        }
    }

    private function applyAutomaticBlock(string $ip, int $status, int $now, ?IpBlock $previous): void
    {
        if ($this->blocking === null) {
            return;
        }
        try {
            $this->blocking->afterResponse($ip, $status, $now, $previous);
        } catch (Throwable $e) {
            $this->note(sprintf('fx-installments automatic block not applied (%s)', $e::class));
        }
    }

    private static function elapsedMs(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }

    private function logInternal(Throwable $e): void
    {
        $this->note(sprintf(
            'fx-installments INTERNAL: %s at %s:%d',
            $e::class,
            $e->getFile(),
            $e->getLine(),
        ));
    }

    private function note(string $message): void
    {
        try {
            ($this->errorLog)($message);
        } catch (Throwable) {
            // Logging must not turn a response into a broken one.
        }
    }
}
