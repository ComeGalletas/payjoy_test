<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * INV-01 (docs/GUARDRAILS.md): only `InstallmentCalculator::display()` rounds money or rates;
 * `Metrics/ReportFormatter` may format report aggregates (P1-08). This scans the PHP code of
 * `api/src/` (comments, docblocks and string contents ignored, so SQL text doesn't count) for
 * `round(`, `number_format(`, `floatval(`, `(float)` casts and printf-family calls with a float
 * conversion (`%f`, `%F`, `%e`, `%E`, `%g`, `%G`), and fails on any hit outside those two files
 * that isn't listed in KNOWN with its reason. A new hit must be removed, not added to KNOWN,
 * unless it doesn't touch money or a rate.
 */
final class RoundingRuleTest extends TestCase
{
    /** Files that may round or format money (INV-01). */
    private const ALLOWED_FILES = ['Convert/InstallmentCalculator.php', 'Metrics/ReportFormatter.php'];

    /**
     * Hits that don't round or format money or rates, file => kind => count.
     * - ConfigRepository: float() returns a float config value (`llm.confidence_threshold`).
     * - ScaledRate::toRate(): the stored decimal's nearest double, for the raw `rate` number,
     *   never shown (ADR 0017 second amendment).
     * - ExchangeRateClient: checks that upstream's number is finite and that its text and value
     *   agree before ScaledRate reads the text; nothing is rounded or stored from the float.
     */
    private const KNOWN = [
        'Config/ConfigRepository.php' => ['(float)' => 1],
        'Convert/ScaledRate.php' => ['(float)' => 1],
        'Rates/ExchangeRateClient.php' => ['(float)' => 3],
    ];

    private const ROUNDING_FUNCTIONS = ['round', 'number_format', 'floatval'];
    private const PRINTF_FUNCTIONS = ['printf', 'sprintf', 'vprintf', 'vsprintf', 'fprintf', 'vfprintf'];

    public function testOnlyTheCalculatorRoundsOrFormatsMoney(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            if (in_array($relative, self::ALLOWED_FILES, true)) {
                continue;
            }
            $hits = self::scan((string) file_get_contents($file->getPathname()));
            if ($hits !== []) {
                ksort($hits);
                $found[$relative] = $hits;
            }
        }
        ksort($found);

        self::assertSame(self::KNOWN, $found, 'rounding or float formatting outside InstallmentCalculator (INV-01)');
    }

    public function testTheScannerSeesCodeAndIgnoresCommentsAndStrings(): void
    {
        $code = <<<'PHP'
            <?php
            // round(1.5); number_format(2); (float) $x; sprintf('%.2f', 1);
            /** round( in a docblock */
            $sql = 'SELECT ROUND(amount) FROM t';
            $a = ROUND(1.5);
            $b = \round(2.5);
            $c = number_format(3);
            $d = (float) '4';
            $e = sprintf('%.2f', 5);
            $f = sprintf("%'08.3F", 6);
            $g = printf('%e', 7);
            $h = sprintf('%d items, 100%%f off', 8);
            $i = floatval('9');
            PHP;

        self::assertSame(
            ['(float)' => 1, 'floatval(' => 1, 'number_format(' => 1, 'printf float' => 3, 'round(' => 2],
            (static function (array $hits): array { ksort($hits); return $hits; })(self::scan($code)),
        );
    }

    /**
     * @return array<string, int> kind => count
     */
    private static function scan(string $php): array
    {
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));
        $hits = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_DOUBLE_CAST) {
                $hits['(float)'] = ($hits['(float)'] ?? 0) + 1;
                continue;
            }
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            if (in_array($name, self::ROUNDING_FUNCTIONS, true)) {
                $hits[$name . '('] = ($hits[$name . '('] ?? 0) + 1;
            } elseif (in_array($name, self::PRINTF_FUNCTIONS, true) && self::callHasFloatFormat($tokens, $i + 1)) {
                $hits['printf float'] = ($hits['printf float'] ?? 0) + 1;
            }
        }

        return $hits;
    }

    /**
     * Whether a string literal inside the call starting at $open has a float conversion.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function callHasFloatFormat(array $tokens, int $open): bool
    {
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                if (--$depth === 0) {
                    return false;
                }
            } elseif (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = str_replace('%%', '', $token[1]);
                if (preg_match("/%(?:\\d+\\$)?[-+ 0]*(?:'.)?[-+ 0]*\\d*(?:\\.\\d+)?[eEfFgG]/", $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
