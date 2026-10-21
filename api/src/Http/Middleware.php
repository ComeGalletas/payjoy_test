<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * A step that runs before a route handler. It either returns its own response (for example an
 * error envelope) or calls $next to continue down the chain.
 */
interface Middleware
{
    /**
     * @param callable(Request): JsonResponse $next
     */
    public function process(Request $request, callable $next): JsonResponse;
}
