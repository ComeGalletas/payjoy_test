<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\AdminAuth;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
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
        yield 'uppercase scheme' => ['BEARER ' . self::TOKEN];
        yield 'several spaces after the scheme' => ['Bearer   ' . self::TOKEN];
    }

    /**
     * P1-07: the parse is strict. The right token with anything around it is refused.
     */
    #[DataProvider('looseHeaders')]
    public function testTheRightTokenWithExtraCharactersIsRefused(string $header): void
    {
        $response = $this->authenticate(self::TOKEN, ['Authorization' => $header]);

        self::assertSame(401, $response->status());
        self::assertSame('ADMIN_UNAUTHORIZED', $response->errorCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function looseHeaders(): iterable
    {
        yield 'trailing newline' => ['Bearer ' . self::TOKEN . "\n"];
        yield 'trailing CRLF' => ['Bearer ' . self::TOKEN . "\r\n"];
        yield 'trailing CR' => ['Bearer ' . self::TOKEN . "\r"];
        yield 'trailing space' => ['Bearer ' . self::TOKEN . ' '];
        yield 'trailing tab' => ['Bearer ' . self::TOKEN . "\t"];
        yield 'trailing vertical tab' => ['Bearer ' . self::TOKEN . "\x0B"];
        yield 'trailing form feed' => ['Bearer ' . self::TOKEN . "\f"];
        yield 'trailing NUL' => ['Bearer ' . self::TOKEN . "\0"];
        yield 'trailing non-breaking space' => ['Bearer ' . self::TOKEN . "\u{00A0}"];
        yield 'leading space' => [' Bearer ' . self::TOKEN];
        yield 'leading newline' => ["\nBearer " . self::TOKEN];
        yield 'tab after the scheme' => ["Bearer\t" . self::TOKEN];
        yield 'newline after the scheme' => ["Bearer\n" . self::TOKEN];
        yield 'no space after the scheme' => ['Bearer' . self::TOKEN];
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
     * allows() (used by `/health` to pick its admin view, P1-08) gives the same answer as the
     * middleware for every case above.
     */
    public function testAllowsAgreesWithTheMiddleware(): void
    {
        foreach (self::acceptedHeaders() as $name => [$header]) {
            self::assertTrue((new AdminAuth(self::TOKEN))->allows(new Request('GET', '/admin', [], ['Authorization' => $header])), $name);
        }
        foreach (self::looseHeaders() as $name => [$header]) {
            self::assertFalse((new AdminAuth(self::TOKEN))->allows(new Request('GET', '/admin', [], ['Authorization' => $header])), $name);
        }
        foreach (self::refusedRequests() as $name => $case) {
            self::assertFalse((new AdminAuth($case[0]))->allows(new Request('GET', '/admin', $case[2] ?? [], $case[1])), $name);
        }
        self::assertSame([401, 'Bearer'], [AdminAuth::unauthorized()->status(), AdminAuth::unauthorized()->header('WWW-Authenticate')]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function authenticate(?string $configured, array $headers, array $query = []): Response
    {
        return (new AdminAuth($configured))->process(
            new Request('GET', '/admin', $query, $headers),
            static fn (): JsonResponse => JsonResponse::ok(['passed' => true]),
        );
    }
}
