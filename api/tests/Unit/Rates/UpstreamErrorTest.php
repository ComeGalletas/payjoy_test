<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\UpstreamError;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The `unsupported-code` backoff marking (ADR 0012 amendment item 9).
 */
final class UpstreamErrorTest extends TestCase
{
    public function testUnsupportedCodeIs400UnlessAnsweredDuringItsBackoff(): void
    {
        $plain = new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'not_in_table');
        self::assertFalse($plain->duringUnsupportedCodeBackoff);
        self::assertSame(400, $plain->status());
        self::assertSame('That currency is not supported right now.', $plain->messageForUser());

        $marked = (new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code', 404))->duringBackoff();
        self::assertTrue($marked->duringUnsupportedCodeBackoff);
        self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $marked->errorCode);
        self::assertSame('unsupported-code', $marked->reason);
        self::assertSame(404, $marked->httpStatus);
        self::assertSame(503, $marked->status());
        self::assertSame('Exchange rates are temporarily unavailable. Please try again later.', $marked->messageForUser());
        self::assertNull($marked->getPrevious());
        self::assertSame('UPSTREAM_UNSUPPORTED_CODE (unsupported-code)', $marked->getMessage());
    }

    public function testOnlyUnsupportedCodeCanBeMarked(): void
    {
        foreach (UpstreamError::CODES as $code) {
            if ($code === ErrorCodes::UPSTREAM_UNSUPPORTED_CODE) {
                continue;
            }
            try {
                new UpstreamError($code, 'x', null, true);
                self::fail("{$code} was marked");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheCodesOwnStatusStays400(): void
    {
        // Not remapped globally: only the marked error answers 503.
        self::assertSame(400, ErrorCodes::httpStatus(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE));
        self::assertSame(503, ErrorCodes::httpStatus(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, true));
        self::assertSame('Exchange rates are temporarily unavailable. Please try again later.', ErrorCodes::messageForUser(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, true));
        self::assertSame(ErrorCodes::messageForUser(ErrorCodes::UPSTREAM_QUOTA), ErrorCodes::messageForUser(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, true));

        foreach (ErrorCodes::all() as $code) {
            if ($code === ErrorCodes::UPSTREAM_UNSUPPORTED_CODE) {
                continue;
            }
            foreach ([static fn () => ErrorCodes::httpStatus($code, true), static fn () => ErrorCodes::messageForUser($code, true)] as $call) {
                try {
                    $call();
                    self::fail("{$code} accepted the backoff flag");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
