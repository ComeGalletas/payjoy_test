<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\Request;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $get = [];

    #[Before]
    protected function saveGlobals(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
    }

    #[After]
    protected function restoreGlobals(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
    }

    public function testFromGlobalsReadsMethodPathQueryHeadersAndPeer(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/convert?amount=200&cy=BRL';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_GET = ['amount' => '200', 'cy' => 'BRL'];

        $request = Request::fromGlobals();

        self::assertSame('POST', $request->method());
        self::assertSame('/convert', $request->path());
        self::assertSame('200', $request->queryParam('amount'));
        self::assertSame(['amount' => '200', 'cy' => 'BRL'], $request->query());
        self::assertSame('10.0.0.1', $request->header('X-Forwarded-For'));
        self::assertSame('Bearer abc', $request->header('authorization'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('198.51.100.4', $request->remoteAddr());
    }

    public function testRedirectedAuthorizationHeaderIsRead(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer xyz';

        self::assertSame('Bearer xyz', Request::fromGlobals()->header('Authorization'));
    }

    public function testPathAlwaysStartsWithASlashAndNeverHoldsTheQuery(): void
    {
        $_SERVER['REQUEST_URI'] = '?token=abc';
        self::assertSame('/', Request::fromGlobals()->path());

        $_SERVER['REQUEST_URI'] = '//evil.example/x?y=1';
        self::assertSame('//evil.example/x', Request::fromGlobals()->path());
    }

    public function testNonStringQueryValuesAreNotReturnedAsStrings(): void
    {
        $request = new Request('GET', '/', ['cy' => ['BRL']]);

        self::assertNull($request->queryParam('cy'));
        self::assertNull($request->queryParam('missing'));
    }

    public function testConstructorLowercasesHeaderNames(): void
    {
        $request = new Request('GET', '/', [], ['Origin' => 'https://a.example']);

        self::assertSame(['origin' => 'https://a.example'], $request->headers());
        self::assertSame('https://a.example', $request->header('ORIGIN'));
    }
}
