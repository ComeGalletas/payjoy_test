<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\JsonResponse;
use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testOkWrapsDataInTheEnvelope(): void
    {
        $response = JsonResponse::ok(['amount_usd' => 200, 'currency' => 'BRL']);

        self::assertSame(200, $response->status());
        self::assertTrue($response->isOk());
        self::assertNull($response->errorCode());
        self::assertSame('{"ok":true,"data":{"amount_usd":200,"currency":"BRL"}}', $response->body());
    }

    public function testEmptyDataIsAnObject(): void
    {
        self::assertSame('{"ok":true,"data":{}}', JsonResponse::ok()->body());
    }

    public function testListDataIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonResponse::ok(['a', 'b']);
    }

    public function testOkNeedsA2xxStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonResponse::ok([], 404);
    }

    #[DataProvider('apiErrorCodes')]
    public function testErrorUsesTheCodeStatusAndGenericMessage(string $code): void
    {
        $response = JsonResponse::error($code);

        self::assertSame(ErrorCodes::httpStatus($code), $response->status());
        self::assertFalse($response->isOk());
        self::assertSame($code, $response->errorCode());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => $code, 'message_for_user' => ErrorCodes::messageForUser($code)]],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiErrorCodes(): iterable
    {
        foreach (ErrorCodes::all() as $code) {
            if (ErrorCodes::httpStatus($code) !== null) {
                yield $code => [$code];
            }
        }
    }

    public function testErrorAcceptsASpecificMessage(): void
    {
        $response = JsonResponse::error(ErrorCodes::VALIDATION_INSTALLMENTS, 'Too many installments for this amount.');

        self::assertSame(400, $response->status());
        self::assertStringContainsString('"message_for_user":"Too many installments for this amount."', $response->body());
    }

    public function testOnlyUnsupportedCodeHasABackoffAnswer(): void
    {
        $response = JsonResponse::error(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, null, true);

        self::assertSame(503, $response->status());
        self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $response->errorCode());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'UPSTREAM_UNSUPPORTED_CODE', 'message_for_user' => 'Exchange rates are temporarily unavailable. Please try again later.']],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(400, JsonResponse::error(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE)->status());

        // No other code can change its status.
        foreach (ErrorCodes::all() as $code) {
            if ($code === ErrorCodes::UPSTREAM_UNSUPPORTED_CODE) {
                continue;
            }
            try {
                JsonResponse::error($code, null, true);
                self::fail("{$code} took the backoff answer");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEventOnlyCodesCannotBeSent(): void
    {
        $this->expectException(LogicException::class);
        JsonResponse::error(ErrorCodes::BOT_MAX_RETRIES);
    }

    public function testUnknownCodesCannotBeSent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonResponse::error('NOT_A_CODE');
    }

    public function testEveryResponseIsJsonWithNosniff(): void
    {
        foreach ([JsonResponse::ok(), JsonResponse::error(ErrorCodes::NOT_FOUND)] as $response) {
            self::assertSame('application/json', $response->header('content-type'));
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        }
    }

    public function testWithHeaderReturnsACopy(): void
    {
        $original = JsonResponse::ok();
        $copy = $original->withHeader('Retry-After', '30');

        self::assertNull($original->header('retry-after'));
        self::assertSame('30', $copy->header('Retry-After'));
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonResponse::ok()->withHeader('X-Test', "a\r\nSet-Cookie: x=1");
    }

    public function testEncodingFailuresThrow(): void
    {
        $this->expectException(JsonException::class);
        JsonResponse::ok(['text' => "\xB1\x31"]);
    }

    public function testFloatsUseTheShortestExactDigits(): void
    {
        // Bootstrap sets serialize_precision=-1 (docs/PLAN.md §3a).
        self::assertSame('-1', ini_get('serialize_precision'));
        self::assertSame('{"ok":true,"data":{"x":0.30000000000000004,"y":0.3333333333333333}}', JsonResponse::ok([
            'x' => 0.1 + 0.2,
            'y' => 1 / 3,
        ])->body());
    }

    public function testSendWritesTheBody(): void
    {
        $this->expectOutputString('{"ok":true,"data":{"sent":true}}');
        JsonResponse::ok(['sent' => true])->send();
    }
}
