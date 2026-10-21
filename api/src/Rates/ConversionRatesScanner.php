<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use JsonException;
use UnexpectedValueException;

/**
 * Reads the raw number text of every rate in a `/latest/USD` body (ADR 0017 amendment), so a
 * scaled rate is built from upstream's own decimal (`1731936.861971`) instead of a float.
 *
 * It walks the JSON text once, without recursion: the top-level object, then only the members
 * of its `conversion_rates` object; every other value is skipped. Strings are matched with
 * possessive patterns and their escapes decoded by json_decode, so `"BRL"` is `BRL`, as
 * json_decode itself reads it. It is meant to run on a body json_decode has already accepted,
 * and the client cross-checks every number it returns against the decoded value; on anything
 * it doesn't understand it returns null and the whole refresh is treated as malformed.
 */
final class ConversionRatesScanner
{
    public const MEMBER = 'conversion_rates';

    private const WHITESPACE = '/\G[ \t\n\r]*+/';
    private const STRING = '/\G"(?:[^"\\\\]++|\\\\.)*+"/s';
    private const NUMBER = '/\G-?(?:0|[1-9][0-9]*+)(?:\.[0-9]++)?(?:[eE][+-]?[0-9]++)?/';
    private const LITERAL = '/\G(?:true|false|null)/';

    /**
     * @return array{rates: array<array-key, string|null>, duplicates: array<array-key, true>}|null
     *         `rates`: each member's decoded key (an int key for a numeric string, as in
     *         json_decode) => its number text, or null when the value isn't a number;
     *         `duplicates`: keys that appeared more than once. Null when the body has no
     *         `conversion_rates` object, has it twice, or can't be scanned.
     */
    public static function scan(string $json): ?array
    {
        $pos = 0;
        $found = null;
        try {
            self::skipWhitespace($json, $pos);
            self::expect($json, $pos, '{');
            self::skipWhitespace($json, $pos);
            if (self::peek($json, $pos) === '}') {
                $pos++;
            } else {
                while (true) {
                    $key = self::readString($json, $pos);
                    self::skipWhitespace($json, $pos);
                    self::expect($json, $pos, ':');
                    self::skipWhitespace($json, $pos);
                    if ($key === self::MEMBER) {
                        if ($found !== null) {
                            return null;
                        }
                        $found = self::readRates($json, $pos);
                    } else {
                        self::skipValue($json, $pos);
                    }
                    self::skipWhitespace($json, $pos);
                    $char = self::take($json, $pos);
                    if ($char === '}') {
                        break;
                    }
                    if ($char !== ',') {
                        throw new UnexpectedValueException('scan');
                    }
                    self::skipWhitespace($json, $pos);
                }
            }
            self::skipWhitespace($json, $pos);
            if ($pos !== strlen($json)) {
                return null;
            }
        } catch (UnexpectedValueException) {
            return null;
        }

        return $found;
    }

    /**
     * @return array{rates: array<array-key, string|null>, duplicates: array<array-key, true>}
     */
    private static function readRates(string $json, int &$pos): array
    {
        self::expect($json, $pos, '{');
        $rates = [];
        $duplicates = [];
        self::skipWhitespace($json, $pos);
        if (self::peek($json, $pos) === '}') {
            $pos++;

            return ['rates' => $rates, 'duplicates' => $duplicates];
        }
        while (true) {
            $key = self::readString($json, $pos);
            self::skipWhitespace($json, $pos);
            self::expect($json, $pos, ':');
            self::skipWhitespace($json, $pos);
            $number = self::match(self::NUMBER, $json, $pos);
            if ($number === null) {
                self::skipValue($json, $pos);
            }
            if (array_key_exists($key, $rates)) {
                $duplicates[$key] = true;
            }
            $rates[$key] = $number;
            self::skipWhitespace($json, $pos);
            $char = self::take($json, $pos);
            if ($char === '}') {
                return ['rates' => $rates, 'duplicates' => $duplicates];
            }
            if ($char !== ',') {
                throw new UnexpectedValueException('scan');
            }
            self::skipWhitespace($json, $pos);
        }
    }

    private static function skipValue(string $json, int &$pos): void
    {
        $char = self::peek($json, $pos);
        if ($char === '"') {
            self::readString($json, $pos);

            return;
        }
        if ($char === '{' || $char === '[') {
            self::skipComposite($json, $pos);

            return;
        }
        if (self::match(self::NUMBER, $json, $pos) !== null || self::match(self::LITERAL, $json, $pos) !== null) {
            return;
        }
        throw new UnexpectedValueException('scan');
    }

    /**
     * Skips one object or array, counting brackets outside strings.
     */
    private static function skipComposite(string $json, int &$pos): void
    {
        $length = strlen($json);
        $depth = 0;
        while ($pos < $length) {
            $pos += strcspn($json, '"{}[]', $pos);
            $char = self::peek($json, $pos);
            if ($char === '"') {
                self::readString($json, $pos);
                continue;
            }
            if ($char === null) {
                break;
            }
            $pos++;
            $depth += ($char === '{' || $char === '[') ? 1 : -1;
            if ($depth === 0) {
                return;
            }
            if ($depth < 0) {
                break;
            }
        }
        throw new UnexpectedValueException('scan');
    }

    /**
     * @return array-key the decoded string; a numeric string becomes an int, as an array key
     */
    private static function readString(string $json, int &$pos): int|string
    {
        $token = self::match(self::STRING, $json, $pos);
        if ($token === null) {
            throw new UnexpectedValueException('scan');
        }
        try {
            $decoded = json_decode($token, false, 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('scan');
        }
        if (!is_string($decoded)) {
            throw new UnexpectedValueException('scan');
        }
        // The same key normalisation PHP applies to json_decode's arrays.
        $keyed = [$decoded => true];

        return array_key_first($keyed);
    }

    private static function match(string $pattern, string $json, int &$pos): ?string
    {
        if (preg_match($pattern, $json, $match, 0, $pos) !== 1 || $match[0] === '') {
            return null;
        }
        $pos += strlen($match[0]);

        return $match[0];
    }

    private static function skipWhitespace(string $json, int &$pos): void
    {
        self::match(self::WHITESPACE, $json, $pos);
    }

    private static function expect(string $json, int &$pos, string $char): void
    {
        if (self::take($json, $pos) !== $char) {
            throw new UnexpectedValueException('scan');
        }
    }

    private static function take(string $json, int &$pos): ?string
    {
        $char = self::peek($json, $pos);
        if ($char !== null) {
            $pos++;
        }

        return $char;
    }

    private static function peek(string $json, int $pos): ?string
    {
        return $pos < strlen($json) ? $json[$pos] : null;
    }
}
