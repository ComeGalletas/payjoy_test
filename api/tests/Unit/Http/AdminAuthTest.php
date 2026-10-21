<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\AdminAuth;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private const TOKEN = 'k3Y-with.punctuation_and~length-0123456789';

    #[DataProvider('acceptedHeaders')]
    public function testTheRightBearerTokenPasses(string $header): void
    {
        self::assertSame(200, $this->authenticate(self::TOKEN, ['Authorization' => $header])->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedHeaders(): iterable
    {
        yield 'canonical' => ['Bearer ' . self::TOKEN];
        yield 'lowercase scheme' => ['bearer ' . self::TOKEN];
        yield 'extra spaces' => ['Bearer   ' . self::TOKEN . ' '];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    #[DataProvider('refusedRequests')]
    public function testEverythingElseIsRefused(?string $configured, array $headers, array $query = []): void
    {
        $response = $this->authenticate($configured, $headers, $query);

        self::assertSame(401, $response->status());
        self::assertSame('ADMIN_UNAUTHORIZED', $response->errorCode());
        self::assertSame('Bearer', $response->header('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string|null, array<string, string>, 2?: array<string, string>}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'no header' => [self::TOKEN, []];
        yield 'wrong token' => [self::TOKEN, ['Authorization' => 'Bearer nope']];
        yield 'token prefix' => [self::TOKEN, ['Authorization' => 'Bearer ' . substr(self::TOKEN, 0, -1)]];
        yield 'token with suffix' => [self::TOKEN, ['Authorization' => 'Bearer ' . self::TOKEN . 'x']];
        yield 'token with inner text' => [self::TOKEN, ['Authorization' => 'Bearer ' . self::TOKEN . ' extra']];
        yield 'other scheme' => [self::TOKEN, ['Authorization' => 'Basic ' . self::TOKEN]];
        yield 'bare token' => [self::TOKEN, ['Authorization' => self::TOKEN]];
        yield 'empty bearer' => [self::TOKEN, ['Authorization' => 'Bearer ']];
        yield 'token in another header' => [self::TOKEN, ['X-Admin-Token' => self::TOKEN]];
        yield 'token in the query string' => [self::TOKEN, [], ['token' => self::TOKEN, 'admin_token' => self::TOKEN]];
        yield 'unset token, no header' => [null, []];
        yield 'unset token, empty bearer' => [null, ['Authorization' => 'Bearer ']];
        yield 'empty token, empty bearer' => ['', ['Authorization' => 'Bearer ']];
        yield 'empty token, bare scheme' => ['', ['Authorization' => 'Bearer']];
        yield 'empty token, empty header' => ['', ['Authorization' => '']];
        yield 'empty token, any bearer' => ['', ['Authorization' => 'Bearer anything']];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function authenticate(?string $configured, array $headers, array $query = []): JsonResponse
    {
        return (new AdminAuth($configured))->process(
            new Request('GET', '/admin', $query, $headers),
            static fn (): JsonResponse => JsonResponse::ok(['passed' => true]),
        );
    }
}
