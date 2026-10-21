<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Convert\UsdCents;

/**
 * The bot's lenient amount reader (docs/PLAN.md §3c, ADR 0013 and its 2026-10-21 owner
 * decisions). `/convert` never uses it.
 *
 * It only translates what the user typed into a canonical amount string; the strict
 * `AmountValidator` then decides. All work is on ASCII bytes and strings: no floats, no locale.
 * Whenever a reading could give a wrong number, it is unclear and the bot asks again.
 *
 * Rules, in order:
 * 1. Trim leading and trailing whitespace (`EdgeWhitespace`: space, tab, CR, LF, no-break
 *    space U+00A0, narrow no-break space U+202F). Then strip one currency marker (`US$`, `USD`
 *    or `$`, any case) at the start and one at the end, each with optional whitespace between
 *    it and the number. A marker anywhere else (`5$5`, `1USD000`, `usdusd5`, `US$$5`) or
 *    spelled with spaces (`U SD5`) is left in place, so rule 2 makes the text unclear.
 * 2. Anything left except ASCII digits, `.`, `,` and inner spaces (space, U+00A0, U+202F) →
 *    unclear. So `1e3`, `-5`, `NaN`, unicode digits, NUL bytes, and a tab, CR or LF inside the
 *    number (`200\n300`, `1\t000`) are unclear; at the ends they are only trimmed.
 * 3. Both `.` and `,` present: the last one is the decimal separator, appears once and has 1–2
 *    digits after it; the other is a thousands separator with groups of exactly 3 digits.
 * 4. One kind, more than once: thousands separators only, groups of 3.
 * 5. One kind, once: 1–2 digits after it → decimal. Exactly 3 digits after a `,` → thousands
 *    (`1,000` → 1000); exactly 3 after a `.` → unclear (`200.505`, `1.500`: a decimal typed with
 *    three places, or thousands? never guessed). Anything else → unclear.
 * 6. Leading or trailing separator, empty groups (two separators in a row, including a double
 *    space), or more than 2 decimals → unclear.
 *
 * An inner space (space, U+00A0 or U+202F) is a thousands separator only: every group after
 * the first must be exactly 3 digits (`1 000`, `1 000 000`; `200 3` and `2 50` are unclear).
 * It may be followed by one decimal `.` or `,` with 1–2 digits (`1 000,50` → 1000.50);
 * combined with `.` or `,` used as a second thousands separator it is unclear (`1 000.000`).
 *
 * Thousands grouping also requires a first group of 1–3 digits that doesn't start with `0`, so
 * `0,500` and `1234,567` are unclear rather than read as 500 and 1234567.
 * Input longer than MAX_INPUT_BYTES is unclear.
 */
final class AmountParser
{
    /** Longer input is unclear before any rule runs; real amounts are far shorter. */
    public const MAX_INPUT_BYTES = 64;

    /** The only characters that may separate thousands inside a number. @var list<string> */
    private const INNER_SPACES = [' ', "\u{00A0}", "\u{202F}"];

    /** Longest first, so `US$` isn't read as `US` plus `$`. @var list<string> */
    private const CURRENCY_MARKERS = ['US$', 'USD', '$'];

    public function __construct(private readonly AmountValidator $validator)
    {
    }

    /**
     * Rules 1–6 then the strict validator.
     */
    public function read(string $text): AmountReading
    {
        $canonical = self::normalize($text);
        if ($canonical === null) {
            return AmountReading::unclear();
        }
        if (preg_match(UsdCents::PATTERN, $canonical) !== 1) {
            // Read fine, but more than 7 integer digits: above any possible amount.max.
            return AmountReading::invalid(ValidationFailed::amount(ValidationFailed::REASON_OVER_MAX));
        }

        try {
            return AmountReading::ok($this->validator->validate($canonical));
        } catch (ValidationFailed $error) {
            return AmountReading::invalid($error);
        }
    }

    /**
     * Rules 1–6 only. Returns the number read, as ASCII digits without leading zeros and, when a
     * decimal part was typed, `.` plus exactly two decimals (`200,5` → `200.50`,
     * `1,000` → `1000`, `007` → `7`); or null for unclear. Range is not checked here.
     *
     * The result is a canonical amount only when its integer part has at most 7 digits. A longer
     * one (`12.345.678` → `12345678`) is returned as read, so `read()` can report it as invalid
     * (`over_max`) rather than unclear; callers other than `read()` must still pass the result
     * through `AmountValidator`.
     */
    public static function normalize(string $text): ?string
    {
        if (strlen($text) > self::MAX_INPUT_BYTES) {
            return null;
        }

        // Rule 1.
        $text = self::stripMarker(self::stripMarker(EdgeWhitespace::trim($text), true), false);
        // Only these become separators; a tab, CR or LF left inside fails rule 2.
        $text = str_replace(self::INNER_SPACES, ' ', $text);

        // Rule 2.
        if (preg_match('/^[0-9., ]+$/D', $text) !== 1) {
            return null;
        }

        // Rule 6: leading or trailing separator, or an empty group between two separators.
        if (preg_match('/^[., ]|[., ]$|[., ]{2}/', $text) === 1) {
            return null;
        }

        if (str_contains($text, ' ')) {
            return self::spaceGrouped($text);
        }

        $dots = substr_count($text, '.');
        $commas = substr_count($text, ',');
        if ($dots + $commas === 0) {
            return self::compose($text, null);
        }
        if ($dots > 0 && $commas > 0) {
            return self::mixedSeparators($text);
        }

        $separator = $dots > 0 ? '.' : ',';
        if ($dots + $commas > 1) {
            // Rule 4.
            return self::compose(self::thousands($text, $separator), null);
        }

        // Rule 5.
        [$integer, $after] = explode($separator, $text);
        $digitsAfter = strlen($after);
        if ($digitsAfter <= 2) {
            return self::compose($integer, $after);
        }
        if ($digitsAfter === 3 && $separator === ',') {
            return self::compose(self::thousands($text, $separator), null);
        }

        return null;
    }

    /**
     * Inner whitespace as the thousands separator, optionally followed by one decimal `.` or `,`.
     */
    private static function spaceGrouped(string $text): ?string
    {
        $dots = substr_count($text, '.');
        $commas = substr_count($text, ',');
        if ($dots + $commas > 1) {
            // A second thousands separator kind, or two decimals.
            return null;
        }
        if ($dots + $commas === 0) {
            return self::compose(self::thousands($text, ' '), null);
        }

        [$integer, $fraction] = explode($dots > 0 ? '.' : ',', $text);
        if (str_contains($fraction, ' ') || strlen($fraction) > 2) {
            return null;
        }

        return self::compose(self::thousands($integer, ' '), $fraction);
    }

    /**
     * Rule 3: the last separator is the decimal one.
     */
    private static function mixedSeparators(string $text): ?string
    {
        $lastDot = (int) strrpos($text, '.');
        $lastComma = (int) strrpos($text, ',');
        $decimal = $lastDot > $lastComma ? '.' : ',';
        $thousands = $decimal === '.' ? ',' : '.';

        if (substr_count($text, $decimal) !== 1) {
            return null;
        }
        $position = max($lastDot, $lastComma);
        $fraction = substr($text, $position + 1);
        if (strlen($fraction) > 2) {
            return null;
        }

        return self::compose(self::thousands(substr($text, 0, $position), $thousands), $fraction);
    }

    /**
     * Digits grouped by one separator: a first group of 1–3 digits not starting with `0`, then
     * groups of exactly 3. Returns the digits without separators, or null.
     */
    private static function thousands(string $text, string $separator): ?string
    {
        $groups = explode($separator, $text);
        $first = array_shift($groups);
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $first) !== 1) {
            return null;
        }
        foreach ($groups as $group) {
            if (preg_match('/^[0-9]{3}$/D', $group) !== 1) {
                return null;
            }
        }

        return $first . implode('', $groups);
    }

    /**
     * @param string|null $integer digits, or null when an earlier step found the text unclear
     * @param string|null $fraction 1–2 digits, or null for no decimal part
     */
    private static function compose(?string $integer, ?string $fraction): ?string
    {
        if ($integer === null || $integer === '') {
            return null;
        }
        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }

        return $fraction === null ? $integer : $integer . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * Removes one currency marker (any case) at the start or the end, plus the whitespace
     * between it and the number. ASCII-only comparison (`strcasecmp` is locale-independent
     * since PHP 8.2).
     */
    private static function stripMarker(string $text, bool $atStart): string
    {
        foreach (self::CURRENCY_MARKERS as $marker) {
            $length = strlen($marker);
            if (strlen($text) < $length) {
                continue;
            }
            $edge = $atStart ? substr($text, 0, $length) : substr($text, -$length);
            if (strcasecmp($edge, $marker) === 0) {
                return EdgeWhitespace::trim($atStart ? substr($text, $length) : substr($text, 0, -$length));
            }
        }

        return $text;
    }
}
