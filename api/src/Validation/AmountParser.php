<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Convert\UsdCents;

/**
 * The bot's lenient amount reader (docs/PLAN.md §3c, ADR 0013). `/convert` never uses it.
 *
 * It only translates what the user typed into a canonical amount string; the strict
 * `AmountValidator` then decides. All work is on ASCII bytes and strings: no floats, no locale.
 *
 * Rules, in order:
 * 1. Strip spaces (space, tab, CR, LF, no-break space U+00A0, narrow no-break space U+202F),
 *    then `US$`, `USD` and `$` in any case, wherever they appear.
 * 2. Anything left except ASCII digits, `.` and `,` → unclear (so `1e3`, `-5`, `NaN`, unicode
 *    digits and NUL bytes are unclear).
 * 3. Both `.` and `,` present: the last one is the decimal separator, appears once and has 1–2
 *    digits after it; the other is a thousands separator with groups of exactly 3 digits.
 * 4. One kind, more than once: thousands separators only, groups of 3.
 * 5. One kind, once: 1–2 digits after it → decimal; exactly 3 → thousands; anything else →
 *    unclear.
 * 6. Leading or trailing separator, empty groups, or more than 2 decimals → unclear.
 *
 * Thousands grouping (rules 3–5) also requires a first group of 1–3 digits that doesn't start
 * with `0`, so `0,500` and `1234,567` are unclear rather than read as 500 and 1234567.
 * Input longer than MAX_INPUT_BYTES is unclear.
 */
final class AmountParser
{
    /** Longer input is unclear before any rule runs; real amounts are far shorter. */
    public const MAX_INPUT_BYTES = 64;

    /** @var list<string> */
    private const SPACES = [' ', "\t", "\r", "\n", "\u{00A0}", "\u{202F}"];

    /** Longest first, so `US$` isn't left as `US` after `$` is removed. @var list<string> */
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
     * `1,000` → `1000`, `007` → `7`); or null for unclear. The result is a canonical amount
     * whenever its integer part has at most 7 digits. Range is not checked here.
     */
    public static function normalize(string $text): ?string
    {
        if (strlen($text) > self::MAX_INPUT_BYTES) {
            return null;
        }

        // Rule 1.
        $text = str_replace(self::SPACES, '', $text);
        $text = str_ireplace(self::CURRENCY_MARKERS, '', $text);

        // Rule 2.
        if (preg_match('/^[0-9.,]+$/D', $text) !== 1) {
            return null;
        }

        $dots = substr_count($text, '.');
        $commas = substr_count($text, ',');
        if ($dots + $commas === 0) {
            return self::compose($text, null);
        }

        // Rule 6: leading or trailing separator, or an empty group between two separators.
        if (preg_match('/^[.,]|[.,]$|[.,]{2}/', $text) === 1) {
            return null;
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
        if ($digitsAfter === 3) {
            return self::compose(self::thousands($text, $separator), null);
        }

        return null;
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
}
