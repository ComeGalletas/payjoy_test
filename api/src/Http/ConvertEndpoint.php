<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Convert\ConvertService;
use FxInstallments\Convert\MinorUnits;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Validation\ValidationFailed;

/**
 * `GET /convert?amount=<usd>&cy=<code>&installments=<n?>` (docs/PLAN.md §1, §6). App::router()
 * registers it behind the RateLimiter (the one global `ratelimit.per_minute`, ADR 0016 second
 * amendment).
 *
 * Query parameters:
 * - `amount` and `cy` are required; absent, or given as an array (`amount[]=1`), they fail
 *   with reason `format`. `installments` is optional: absent means `installments.default`;
 *   present but empty or an array fails with `format`.
 * - Validation order: amount, then currency, then installments; the first failure answers.
 *   Nothing reaches the rate cache until all three are valid.
 * - Other parameters are ignored. A repeated plain parameter keeps PHP's behaviour (the last
 *   value wins, `$_GET`).
 *
 * Errors: a ValidationFailed answers 400 with its code; an UpstreamError answers with its own
 * status and message (UpstreamError::status(): 503, or 400 for UPSTREAM_UNSUPPORTED_CODE
 * except during its backoff, ADR 0012 amendment item 9). Anything else reaches the Kernel as
 * 500 INTERNAL.
 *
 * Every answered request writes one `api.request` event (source `api`): the validated
 * currency, `amount_usd_cents` and installments as far as validation got, status, error code,
 * latency, and meta `endpoint=convert`, `http_status`, `stale` on success, `reason` on failure
 * (a fixed word), and `minor_units_default=true` for a currency MinorUnits doesn't list. Never
 * raw input, upstream text, keys or IPs.
 */
final class ConvertEndpoint
{
    public const PATH = '/convert';

    /**
     * @param Closure(): ConvertService $service built on first use, so routing never builds
     *        the upstream client
     */
    public function __construct(
        private readonly Closure $service,
        private readonly Metrics $metrics,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $fields = [];
        $meta = ['endpoint' => 'convert'];

        try {
            $service = ($this->service)();
            $amount = $service->amount($request->queryParam('amount'));
            $fields['amount_usd_cents'] = $amount->cents;
            $currency = $service->currency($request->queryParam('cy'));
            $fields['currency'] = $currency;
            if (!MinorUnits::isKnown($currency)) {
                $meta['minor_units_default'] = true;
            }
            $installments = $service->installments(self::installmentsParam($request));
            $fields['installments'] = $installments;

            $conversion = $service->convert($amount, $currency, $installments);
            $response = JsonResponse::ok($conversion->toArray());
            $meta['stale'] = $conversion->stale;
        } catch (ValidationFailed $e) {
            $response = JsonResponse::error($e->errorCode);
            $meta['reason'] = $e->reason;
        } catch (UpstreamError $e) {
            $response = JsonResponse::error($e->errorCode, $e->messageForUser(), $e->status());
            $meta['reason'] = $e->reason;
        }

        $meta['http_status'] = $response->status();
        $this->metrics->record('api.request', $fields + [
            'source' => 'api',
            'error_code' => $response->errorCode(),
            'latency_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'meta' => $meta,
        ]);

        return $response;
    }

    /**
     * The raw `installments` value, or null when the parameter is absent.
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS `format` for an array value
     */
    private static function installmentsParam(Request $request): ?string
    {
        $query = $request->query();
        if (!array_key_exists('installments', $query)) {
            return null;
        }
        $value = $query['installments'];
        if (!is_string($value)) {
            throw ValidationFailed::installments(ValidationFailed::REASON_FORMAT);
        }

        return $value;
    }
}
