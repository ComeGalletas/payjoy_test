<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Validation\AmountParser;
use FxInstallments\Validation\AmountValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/PLAN.md §3c, one table per rule with inputs on both sides of it. A null expectation
 * means "unclear".
 */
final class AmountParserTest extends TestCase
{
    /**
     * Rule 1: strip `$`, `US$`, `USD` (any case) and spaces.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule1Stripping(): iterable
    {
        yield 'dollar prefix' => ['$200', '200'];
        yield 'dollar suffix' => ['200$', '200'];
        yield 'US$ prefix' => ['US$200', '200'];
        yield 'us$ lowercase' => ['us$ 200', '200'];
        yield 'USD suffix' => ['200 USD', '200'];
        yield 'usd lowercase prefix' => ['usd200', '200'];
        yield 'UsD mixed case' => ['200.50 UsD', '200.50'];
        yield 'symbol and code together' => ['$ 1,000.50 USD', '1000.50'];
        yield 'surrounding spaces' => ['  200  ', '200'];
        yield 'tab and newline' => ["\t200\n", '200'];
        yield 'space as thousands' => ['1 000', '1000'];
        yield 'no-break space' => ["1\u{00A0}000,50", '1000.50'];
        yield 'narrow no-break space' => ["1\u{202F}000", '1000'];
        // Other side: only those markers and spaces are stripped.
        yield 'US without $' => ['US 200', null];
        yield 'other currency code' => ['200 EUR', null];
        yield 'euro sign' => ['€200', null];
        yield 'only a dollar sign' => ['$', null];
        yield 'only USD' => ['USD', null];
        yield 'empty' => ['', null];
        yield 'only spaces' => ['   ', null];
    }

    /**
     * Rule 2: anything left except digits, `.` and `,` is unclear.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule2Characters(): iterable
    {
        yield 'digits' => ['200', '200'];
        yield 'digits and a separator' => ['200.5', '200.50'];
        yield 'exponent' => ['1e3', null];
        yield 'NaN' => ['NaN', null];
        yield 'INF' => ['INF', null];
        yield 'minus' => ['-5', null];
        yield 'plus' => ['+5', null];
        yield 'letters' => ['two hundred', null];
        yield 'apostrophe grouping' => ["1'000", null];
        yield 'underscore grouping' => ['1_000', null];
        yield 'hex' => ['0x1A', null];
        yield 'fullwidth digits' => ['１２３', null];
        yield 'arabic-indic digits' => ['٢٠٠', null];
        yield 'devanagari digits' => ['२००', null];
        yield 'NUL byte inside' => ["20\x000", null];
        yield 'NUL byte at the end' => ["200\x00", null];
        yield 'invalid UTF-8' => ["200\xC3", null];
    }

    /**
     * Rule 3: both separators: the last is the decimal one (1–2 digits, appears once); the other
     * is thousands with groups of exactly 3.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule3MixedSeparators(): iterable
    {
        yield 'comma decimal' => ['1.000,50', '1000.50'];
        yield 'dot decimal' => ['1,000.50', '1000.50'];
        yield 'one decimal digit' => ['1.000,5', '1000.50'];
        yield 'millions' => ['1.000.000,25', '1000000.25'];
        yield 'millions, dot decimal' => ['1,234,567.89', '1234567.89'];
        yield 'three-digit first group' => ['999.999,99', '999999.99'];
        yield 'three decimals' => ['1.000,505', null];
        yield 'decimal separator twice' => ['1,000.000,50', null];
        yield 'thousands after decimal' => ['1,000.50,5', null];
        yield 'thousands group of 2' => ['1.00,50', null];
        yield 'thousands group of 4' => ['1.0000,50', null];
        yield 'first group of 4' => ['1000.000,50', null];
        yield 'first group with leading zero' => ['0.000,50', null];
    }

    /**
     * Rule 4: one kind, more than once: thousands only, groups of 3.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule4RepeatedSeparator(): iterable
    {
        yield 'dots' => ['1.000.000', '1000000'];
        yield 'commas' => ['1,000,000', '1000000'];
        yield 'two-digit first group' => ['12.345.678', '12345678'];
        yield 'groups of 1' => ['1,2,3', null];
        yield 'last group of 2' => ['1.000.00', null];
        yield 'group of 2 in the middle' => ['1.00.000', null];
        yield 'group of 4' => ['1,0000,000', null];
        yield 'repeated, first group of 4' => ['1000.000.000', null];
        yield 'repeated, first group with leading zero' => ['01.000.000', null];
    }

    /**
     * Rule 5: one kind, once: 1–2 digits → decimal; exactly 3 → thousands; else unclear.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule5SingleSeparator(): iterable
    {
        yield 'comma, one digit' => ['200,5', '200.50'];
        yield 'comma, two digits' => ['200,55', '200.55'];
        yield 'dot, one digit' => ['200.5', '200.50'];
        yield 'dot, two digits' => ['200.55', '200.55'];
        yield 'zero point five' => ['0,5', '0.50'];
        yield 'comma, three digits' => ['1,000', '1000'];
        yield 'dot, three digits' => ['1.000', '1000'];
        yield 'dot, three digits after three' => ['200.505', '200505'];
        yield 'comma, four digits' => ['1,0000', null];
        yield 'comma, five digits' => ['1,00000', null];
        yield 'thousands with a first group of 4' => ['1234,567', null];
        yield 'thousands with a leading-zero first group' => ['0,500', null];
        yield 'a long integer part with decimals' => ['1234567,5', '1234567.50'];
    }

    /**
     * Rule 6: leading or trailing separator, empty groups, more than 2 decimals.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function rule6Malformed(): iterable
    {
        yield 'leading dot' => ['.50', null];
        yield 'leading comma' => [',50', null];
        yield 'trailing dot' => ['200.', null];
        yield 'trailing comma' => ['200,', null];
        yield 'empty group' => ['1..000', null];
        yield 'empty group, mixed' => ['1.,000', null];
        yield 'only separators' => ['.,', null];
        yield 'more than 2 decimals, mixed' => ['1,000.505', null];
        yield 'not malformed' => ['200.50', '200.50'];
    }

    /**
     * Leading zeros and the 7-digit limit.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function leadingZerosAndLength(): iterable
    {
        yield 'leading zeros dropped' => ['007', '7'];
        yield 'leading zeros with decimals' => ['007,5', '7.50'];
        yield 'zero' => ['0', '0'];
        yield 'zeros' => ['0000', '0'];
        yield 'eight digits of leading zeros' => ['00000007', '7'];
        yield 'eight real digits' => ['12345678', '12345678'];
        yield 'at the input length limit' => [str_repeat('0', AmountParser::MAX_INPUT_BYTES - 1) . '5', '5'];
        yield 'over the input length limit' => [str_repeat('0', AmountParser::MAX_INPUT_BYTES) . '5', null];
        yield 'very long input' => [str_repeat('1', 100_000), null];
    }

    #[DataProvider('rule1Stripping')]
    #[DataProvider('rule2Characters')]
    #[DataProvider('rule3MixedSeparators')]
    #[DataProvider('rule4RepeatedSeparator')]
    #[DataProvider('rule5SingleSeparator')]
    #[DataProvider('rule6Malformed')]
    #[DataProvider('leadingZerosAndLength')]
    public function testNormalize(string $text, ?string $expected): void
    {
        self::assertSame($expected, AmountParser::normalize($text));
    }

    public function testReadReturnsTheValidatedAmount(): void
    {
        $reading = self::parser()->read('1.000,50');

        self::assertSame(Outcome::Ok, $reading->outcome);
        self::assertNotNull($reading->amount);
        self::assertSame(100_050, $reading->amount->cents);
        self::assertSame('1000.50', $reading->amount->canonical);
        self::assertNull($reading->error);

        $reading = self::parser()->read('1,000');
        self::assertNotNull($reading->amount);
        self::assertSame('1000.00', $reading->amount->canonical);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unclearTexts(): iterable
    {
        foreach (['1,2,3', '1e3', 'NaN', '-5', '', '$', '0,500', '1.000,505', str_repeat('9', 1000)] as $text) {
            yield var_export(strlen($text) > 20 ? substr($text, 0, 20) . '...' : $text, true) => [$text];
        }
    }

    #[DataProvider('unclearTexts')]
    public function testUnreadableTextIsUnclear(string $text): void
    {
        $reading = self::parser()->read($text);

        self::assertSame(Outcome::Unclear, $reading->outcome);
        self::assertNull($reading->amount);
        self::assertNotNull($reading->error);
        self::assertSame('VALIDATION_AMOUNT', $reading->error->errorCode);
        self::assertSame(ValidationFailed::REASON_UNCLEAR, $reading->error->reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'zero' => ['0', ValidationFailed::REASON_NOT_POSITIVE];
        yield 'zero with decimals' => ['$0,00', ValidationFailed::REASON_NOT_POSITIVE];
        yield 'one cent over amount.max' => ['1.000.000,01', ValidationFailed::REASON_OVER_MAX];
        yield 'seven digits over amount.max' => ['2,000,000', ValidationFailed::REASON_OVER_MAX];
        yield 'eight digits' => ['12.345.678', ValidationFailed::REASON_OVER_MAX];
        yield 'a 40-digit number' => [str_repeat('9', 40), ValidationFailed::REASON_OVER_MAX];
    }

    #[DataProvider('invalidTexts')]
    public function testReadableButOutOfRangeIsInvalid(string $text, string $reason): void
    {
        $reading = self::parser()->read($text);

        self::assertSame(Outcome::Invalid, $reading->outcome);
        self::assertNull($reading->amount);
        self::assertNotNull($reading->error);
        self::assertSame('VALIDATION_AMOUNT', $reading->error->errorCode);
        self::assertSame($reason, $reading->error->reason);
    }

    public function testExactlyAmountMaxIsAccepted(): void
    {
        $reading = self::parser()->read('US$ 1.000.000,00');

        self::assertSame(Outcome::Ok, $reading->outcome);
        self::assertNotNull($reading->amount);
        self::assertSame(100_000_000, $reading->amount->cents);
    }

    public function testEveryNormalizedOutputIsCanonicalOrUnclear(): void
    {
        // A deterministic sweep over short strings of digits and separators: the output is
        // always null or digits without leading zeros, optionally `.` and exactly two decimals.
        $alphabet = ['0', '1', '5', '.', ','];
        $count = 0;
        $strings = [''];
        for ($length = 1; $length <= 6; $length++) {
            $next = [];
            foreach ($strings as $prefix) {
                foreach ($alphabet as $char) {
                    $next[] = $prefix . $char;
                }
            }
            $strings = $next;
            foreach ($strings as $text) {
                $result = AmountParser::normalize($text);
                $count++;
                if ($result !== null) {
                    self::assertMatchesRegularExpression('/^(0|[1-9][0-9]*)(\.[0-9]{2})?$/D', $result, $text);
                }
            }
        }
        self::assertSame(19_530, $count);
    }

    private static function parser(): AmountParser
    {
        return new AmountParser(new AmountValidator(new ConfigRepository()));
    }
}
