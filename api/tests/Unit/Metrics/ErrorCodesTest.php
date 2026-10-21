<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ErrorCodesTest extends TestCase
{
    /**
     * docs/PLAN.md §5, copied by hand so a change on either side is noticed.
     */
    private const SECTION_5 = [
        'VALIDATION_AMOUNT' => 400,
        'VALIDATION_CURRENCY' => 400,
        'VALIDATION_INSTALLMENTS' => 400,
        'UPSTREAM_UNSUPPORTED_CODE' => 400,
        'UPSTREAM_TIMEOUT' => 503,
        'UPSTREAM_QUOTA' => 503,
        'UPSTREAM_AUTH' => 503,
        'UPSTREAM_MALFORMED' => 503,
        'ADMIN_UNAUTHORIZED' => 401,
        'IP_BLOCKED' => 403,
        'NOT_FOUND' => 404,
        'RATE_LIMITED' => 429,
        'BOT_MAX_RETRIES' => null,
        'BOT_SESSION_EXPIRED' => null,
        'LLM_UNAVAILABLE' => null,
        'LLM_LOW_CONFIDENCE' => null,
        'INTERNAL' => 500,
    ];

    public function testEverySection5CodeIsPresentWithItsHttpStatus(): void
    {
        $actual = [];
        foreach (ErrorCodes::all() as $code) {
            $actual[$code] = ErrorCodes::httpStatus($code);
        }

        self::assertSame(self::SECTION_5, $actual);
    }

    public function testConstantsMatchTheirNames(): void
    {
        $constants = (new \ReflectionClass(ErrorCodes::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC);

        self::assertSame(array_keys(self::SECTION_5), array_keys($constants));
        foreach ($constants as $name => $value) {
            self::assertSame($name, $value);
        }
    }

    public function testApiCodesHaveAGenericMessageAndEventCodesHaveNone(): void
    {
        foreach (self::SECTION_5 as $code => $status) {
            $message = ErrorCodes::messageForUser($code);
            if ($status === null) {
                self::assertNull($message, $code);
            } else {
                self::assertIsString($message, $code);
                self::assertNotSame('', $message, $code);
            }
        }
    }

    public function testUnknownCodesAreRejected(): void
    {
        self::assertFalse(ErrorCodes::isKnown('validation_amount'));

        $this->expectException(InvalidArgumentException::class);
        ErrorCodes::httpStatus('SOMETHING_ELSE');
    }
}
