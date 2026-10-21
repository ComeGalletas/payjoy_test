<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use InvalidArgumentException;

/**
 * The single source of truth for error codes (docs/PLAN.md §5): each stable code string, the
 * HTTP status it maps to, and the generic message_for_user an API response carries.
 *
 * Codes with no HTTP status (bot events, Phase 2) can be recorded as events but never sent as
 * an API error.
 */
final class ErrorCodes
{
    public const VALIDATION_AMOUNT = 'VALIDATION_AMOUNT';
    public const VALIDATION_CURRENCY = 'VALIDATION_CURRENCY';
    public const VALIDATION_INSTALLMENTS = 'VALIDATION_INSTALLMENTS';
    public const UPSTREAM_UNSUPPORTED_CODE = 'UPSTREAM_UNSUPPORTED_CODE';
    public const UPSTREAM_TIMEOUT = 'UPSTREAM_TIMEOUT';
    public const UPSTREAM_QUOTA = 'UPSTREAM_QUOTA';
    public const UPSTREAM_AUTH = 'UPSTREAM_AUTH';
    public const UPSTREAM_MALFORMED = 'UPSTREAM_MALFORMED';
    public const ADMIN_UNAUTHORIZED = 'ADMIN_UNAUTHORIZED';
    public const IP_BLOCKED = 'IP_BLOCKED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const BOT_MAX_RETRIES = 'BOT_MAX_RETRIES';
    public const BOT_SESSION_EXPIRED = 'BOT_SESSION_EXPIRED';
    public const LLM_UNAVAILABLE = 'LLM_UNAVAILABLE';
    public const LLM_LOW_CONFIDENCE = 'LLM_LOW_CONFIDENCE';
    public const INTERNAL = 'INTERNAL';

    private const UPSTREAM_UNAVAILABLE_MESSAGE =
        'Exchange rates are temporarily unavailable. Please try again later.';

    /**
     * Code => [HTTP status or null, generic message_for_user or null].
     *
     * @var array<string, array{int|null, string|null}>
     */
    private const TABLE = [
        self::VALIDATION_AMOUNT => [400, 'Please enter a valid total in USD, for example 200 or 200.50.'],
        self::VALIDATION_CURRENCY => [400, 'Please use a supported 3-letter currency code, for example BRL.'],
        self::VALIDATION_INSTALLMENTS => [400, 'Please choose a valid number of installments.'],
        self::UPSTREAM_UNSUPPORTED_CODE => [400, 'That currency is not supported right now.'],
        self::UPSTREAM_TIMEOUT => [503, 'The exchange rate service is not responding. Please try again in a moment.'],
        self::UPSTREAM_QUOTA => [503, self::UPSTREAM_UNAVAILABLE_MESSAGE],
        self::UPSTREAM_AUTH => [503, self::UPSTREAM_UNAVAILABLE_MESSAGE],
        self::UPSTREAM_MALFORMED => [503, self::UPSTREAM_UNAVAILABLE_MESSAGE],
        self::ADMIN_UNAUTHORIZED => [401, 'Admin authorization is required.'],
        // Owner wording (2026-10-21): no duration and no Retry-After, so a blocked client learns
        // nothing about how long the block lasts.
        self::IP_BLOCKED => [403, 'Too many unusual requests came from your network. Please try again later.'],
        self::NOT_FOUND => [404, 'Not found.'],
        self::RATE_LIMITED => [429, 'Too many requests. Please wait a minute and try again.'],
        // Bot wording lives only in the flow file (docs/PLAN.md §9), so bot codes carry no text.
        self::BOT_MAX_RETRIES => [null, null],
        self::BOT_SESSION_EXPIRED => [null, null],
        self::LLM_UNAVAILABLE => [null, null],
        self::LLM_LOW_CONFIDENCE => [null, null],
        self::INTERNAL => [500, 'Something went wrong on our side. Please try again later.'],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLE);
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::TABLE);
    }

    /**
     * The HTTP status for an API error, or null for codes that are recorded as events only.
     */
    public static function httpStatus(string $code): ?int
    {
        return self::entry($code)[0];
    }

    /**
     * The generic text front-ends show verbatim, or null for codes with no API response.
     */
    public static function messageForUser(string $code): ?string
    {
        return self::entry($code)[1];
    }

    /**
     * @return array{int|null, string|null}
     */
    private static function entry(string $code): array
    {
        if (!self::isKnown($code)) {
            throw new InvalidArgumentException("Unknown error code: {$code}");
        }

        return self::TABLE[$code];
    }
}
