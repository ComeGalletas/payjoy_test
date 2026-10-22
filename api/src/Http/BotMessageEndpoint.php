<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Flow\FlowEngine;
use FxInstallments\Metrics\ErrorCodes;
use JsonException;

/**
 * `POST /bot/message {session_id?, text}` → `{session_id, reply, buttons?, step, done}`
 * (docs/PLAN.md §6, §9): the contract every channel uses. App::router() registers it behind
 * Cors (outermost, so even a rate-limited answer carries the CORS headers for an allowed
 * origin) and the
 * RateLimiter (the one global `ratelimit.per_minute`, ADR 0016 second amendment). Sessions
 * started here have channel `static`.
 *
 * The body must be a JSON object of at most MAX_BODY_BYTES, sent with
 * `Content-Type: application/json` (so a page on another origin can't send it without a CORS
 * preflight), holding `text` (a string; `""` starts a session) and optionally `session_id`
 * (a string or null). Other keys are ignored. Anything else answers 400 BAD_REQUEST. A
 * `session_id` that is malformed, unknown, closed or expired starts a new session (§9).
 *
 * Every conversation outcome, including an abandoned session, is a 200 envelope: the bot's
 * text is in `reply`, and `done` says the session ended. Nothing the user typed is logged.
 */
final class BotMessageEndpoint
{
    public const PATH = '/bot/message';
    public const CHANNEL = 'static';
    public const MAX_BODY_BYTES = 4096;

    /**
     * @param Closure(): FlowEngine $engine built on first use, so routing never loads the flow
     */
    public function __construct(private readonly Closure $engine)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $message = self::message($request);
        if ($message === null) {
            return JsonResponse::error(ErrorCodes::BAD_REQUEST);
        }
        [$sessionId, $text] = $message;

        $reply = ($this->engine)()->handle($sessionId, $text, self::CHANNEL);

        return JsonResponse::ok($reply->toArray());
    }

    /**
     * @return array{string|null, string}|null the session id and text, or null for a bad request
     */
    private static function message(Request $request): ?array
    {
        $type = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
        $body = $request->body();
        if ($type !== 'application/json' || strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !array_key_exists('text', $data) || !is_string($data['text'])) {
            return null;
        }
        $sessionId = $data['session_id'] ?? null;
        if ($sessionId !== null && !is_string($sessionId)) {
            return null;
        }

        return [$sessionId, $data['text']];
    }
}
