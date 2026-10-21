<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * The whitespace the bot trims from the ends of what the user typed: space, tab, CR, LF,
 * no-break space U+00A0 and narrow no-break space U+202F. Shared by `AmountParser` and
 * `InstallmentsValidator::fromText()` so both trim the same set.
 *
 * Byte-safe on any input, including invalid UTF-8 (PHP's `trim()` can't remove the multi-byte
 * spaces).
 */
final class EdgeWhitespace
{
    /** @var list<string> */
    public const CHARACTERS = [' ', "\t", "\r", "\n", "\u{00A0}", "\u{202F}"];

    public static function trim(string $text): string
    {
        do {
            $before = $text;
            foreach (self::CHARACTERS as $space) {
                if (str_starts_with($text, $space)) {
                    $text = substr($text, strlen($space));
                }
                if (str_ends_with($text, $space)) {
                    $text = substr($text, 0, -strlen($space));
                }
            }
        } while ($text !== $before);

        return $text;
    }
}
