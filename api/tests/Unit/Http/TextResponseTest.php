<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\TextResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextResponseTest extends TestCase
{
    public function testItIsA200WithItsTypeAndNosniff(): void
    {
        $response = TextResponse::ok('<p>hi</p>', 'text/html; charset=utf-8');

        self::assertSame(200, $response->status());
        self::assertSame('<p>hi</p>', $response->body());
        self::assertNull($response->errorCode());
        self::assertSame(
            ['content-type' => 'text/html; charset=utf-8', 'x-content-type-options' => 'nosniff'],
            $response->headers(),
        );
    }

    public function testWithHeaderReturnsACopyAndRefusesLineBreaks(): void
    {
        $plain = TextResponse::ok('body', 'text/css');
        $withHeader = $plain->withHeader('Cache-Control', 'no-cache');

        self::assertNull($plain->header('cache-control'));
        self::assertSame('no-cache', $withHeader->header('CACHE-CONTROL'));

        $this->expectException(InvalidArgumentException::class);
        $plain->withHeader('X-Test', "a\r\nSet-Cookie: x=1");
    }

    public function testContentTypeAndNosniffCannotBeReplaced(): void
    {
        $response = TextResponse::ok('body', 'text/css')
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('X-Content-Type-Options', 'none');

        self::assertSame('text/css', $response->header('content-type'));
        self::assertSame('nosniff', $response->header('x-content-type-options'));
    }

    #[DataProvider('badContentTypes')]
    public function testAContentTypeMustBeAPlainValue(string $contentType): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextResponse::ok('body', $contentType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badContentTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'no subtype' => ['text'];
        yield 'header injection' => ["text/html\r\nX-Evil: 1"];
        yield 'extra parameter' => ['text/html; charset=utf-8; x=1'];
        yield 'trailing newline' => ["text/html\n"];
    }

    /**
     * P1-07: no response the app builds sets X-Powered-By (PHP's own is removed in index.php).
     */
    public function testNoResponseCarriesXPoweredBy(): void
    {
        foreach ([TextResponse::ok('x', 'text/plain'), JsonResponse::ok(), JsonResponse::error('NOT_FOUND')] as $response) {
            self::assertArrayNotHasKey('x-powered-by', $response->headers());
        }
    }
}
