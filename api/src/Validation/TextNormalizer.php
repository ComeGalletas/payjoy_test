<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * Folds bot text for exact lookups in the synonym table and the currency-country map:
 * trim, collapse inner whitespace to one space, lowercase, and strip Latin accents
 * (`  México ` → `mexico`, `DÓLAR` → `dolar`, `España` → `espana`).
 *
 * mbstring only, with explicit UTF-8: no locale, no intl. Invalid UTF-8 and NUL bytes give
 * null, so they never match anything.
 */
final class TextNormalizer
{
    /** Longer text gives null; the longest real entry is far shorter. */
    public const MAX_INPUT_BYTES = 100;

    /**
     * Lowercase accented Latin letters → their base letter. Applied after mb_strtolower, so
     * uppercase forms are covered too.
     *
     * @var array<string, string>
     */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function fold(string $text): ?string
    {
        if (strlen($text) > self::MAX_INPUT_BYTES
            || str_contains($text, "\0")
            || !mb_check_encoding($text, 'UTF-8')
        ) {
            return null;
        }

        // \s and \p{Z} cover ASCII whitespace plus no-break and other Unicode spaces.
        $text = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        $text = trim($text, ' ');
        $text = mb_strtolower($text, 'UTF-8');

        return strtr($text, self::ACCENTS);
    }
}
