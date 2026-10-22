<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Validation\InstallmentsValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallmentsValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validTexts(): iterable
    {
        yield 'one' => ['1', 1];
        yield 'three' => ['3', 3];
        yield 'twelve' => ['12', 12];
        yield 'installments.max' => ['60', 60];
        yield 'surrounding spaces' => ['  3 ', 3];
        yield 'tab and newline' => ["\t12\n", 12];
        yield 'leading zero' => ['03', 3];
        yield 'two leading zeros' => ['007', 7];
        yield 'leading zero on max' => ['060', 60];
        yield 'many leading zeros' => [str_repeat('0', 40) . '12', 12];
        yield 'leading zero and spaces' => [' 03 ', 3];
        yield 'CRLF at the end' => ["3\r\n", 3];
        yield 'no-break spaces' => ["\u{00A0}12\u{00A0}", 12];
        yield 'narrow no-break space' => ["\u{202F}6", 6];
        yield 'mixed end whitespace' => ["\u{00A0} \t3\n\u{202F}", 3];
    }

    #[DataProvider('validTexts')]
    public function testBotTextIsTrimmedDigits(string $text, int $count): void
    {
        self::assertSame($count, self::validator()->fromText($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTexts(): iterable
    {
        $format = ValidationFailed::REASON_FORMAT;
        $range = ValidationFailed::REASON_OUT_OF_RANGE;
        yield 'zero' => ['0', $range];
        yield 'one over max' => ['61', $range];
        yield 'far over max' => ['999', $range];
        yield 'huge' => [str_repeat('9', 40), $range];
        yield 'negative' => ['-1', $format];
        yield 'plus sign' => ['+3', $format];
        yield 'decimal' => ['3.5', $format];
        yield 'decimal comma' => ['3,5', $format];
        yield 'whole decimal' => ['3.0', $format];
        yield 'exponent' => ['1e1', $format];
        yield 'zeros' => ['00', $range];
        yield 'leading zero over max' => ['061', $range];
        yield 'many zeros' => [str_repeat('0', 40), $range];
        yield 'empty' => ['', $format];
        yield 'only spaces' => ['   ', $format];
        yield 'inner space' => ['1 2', $format];
        yield 'inner no-break space' => ["1\u{00A0}2", $format];
        yield 'inner tab' => ["1\t2", $format];
        yield 'only no-break spaces' => ["\u{00A0}\u{202F}", $format];
        yield 'vertical tab is not trimmed' => ["\v3", $format];
        yield 'word' => ['three', $format];
        yield 'fullwidth digit' => ['３', $format];
        yield 'NUL byte' => ["3\0", $format];
        yield 'hex' => ['0x3', $format];
        yield 'the button label' => ['Just one payment', $format];
    }

    #[DataProvider('invalidTexts')]
    public function testInvalidTextFailsWithValidationInstallments(string $text, string $reason): void
    {
        try {
            self::validator()->fromText($text);
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $error) {
            self::assertSame('VALIDATION_INSTALLMENTS', $error->errorCode);
            self::assertSame($reason, $error->reason);
            self::assertSame('Please choose a valid number of installments.', $error->messageForUser());
        }
    }

    #[DataProvider('validTexts')]
    public function testReadGivesAnOkReading(string $text, int $count): void
    {
        $reading = self::validator()->read($text);

        self::assertSame([Outcome::Ok, $count, null], [$reading->outcome, $reading->count, $reading->error]);
    }

    #[DataProvider('invalidTexts')]
    public function testReadGivesAnInvalidReadingNeverUnclear(string $text, string $reason): void
    {
        $reading = self::validator()->read($text);

        self::assertSame([Outcome::Invalid, null], [$reading->outcome, $reading->count]);
        self::assertNotNull($reading->error);
        self::assertSame(['VALIDATION_INSTALLMENTS', $reason], [$reading->error->errorCode, $reading->error->reason]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'leading space' => [' 3', ValidationFailed::REASON_FORMAT];
        yield 'trailing space' => ['3 ', ValidationFailed::REASON_FORMAT];
        yield 'trailing LF' => ["3\n", ValidationFailed::REASON_FORMAT];
        yield 'leading no-break space' => ["\u{00A0}3", ValidationFailed::REASON_FORMAT];
        yield 'leading zero' => ['03', ValidationFailed::REASON_FORMAT];
        yield 'zeros' => ['00', ValidationFailed::REASON_FORMAT];
        yield 'zero' => ['0', ValidationFailed::REASON_OUT_OF_RANGE];
        yield 'one over max' => ['61', ValidationFailed::REASON_OUT_OF_RANGE];
    }

    #[DataProvider('invalidQueries')]
    public function testQueryValuesAreStrict(string $raw, string $reason): void
    {
        self::assertSame(3, self::validator()->fromQuery('3'));

        try {
            self::validator()->fromQuery($raw);
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $error) {
            self::assertSame($reason, $error->reason);
        }
    }

    public function testIntegersAreRangeChecked(): void
    {
        $validator = self::validator();
        self::assertSame(1, $validator->validate(1));
        self::assertSame(60, $validator->validate(60));

        foreach ([0, -1, 61, PHP_INT_MAX, PHP_INT_MIN] as $count) {
            try {
                $validator->validate($count);
                self::fail("expected {$count} to be rejected");
            } catch (ValidationFailed $error) {
                self::assertSame(ValidationFailed::REASON_OUT_OF_RANGE, $error->reason);
            }
        }
    }

    public function testMaxAndDefaultComeFromConfig(): void
    {
        self::assertSame(60, self::validator()->max());
        self::assertSame(1, self::validator()->defaultCount());

        $validator = new InstallmentsValidator(new ConfigRepository(['installments.max' => 120, 'installments.default' => 3]));
        self::assertSame(120, $validator->max());
        self::assertSame(3, $validator->defaultCount());
        self::assertSame(100, $validator->fromText('100'));
        self::assertSame(120, $validator->fromText('120'));

        $this->expectException(ValidationFailed::class);
        $validator->fromText('121');
    }

    private static function validator(): InstallmentsValidator
    {
        return new InstallmentsValidator(new ConfigRepository());
    }
}
