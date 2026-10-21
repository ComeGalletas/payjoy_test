<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use ArrayObject;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\RequestLog;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestLogTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private PDO $pdo;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->log = new ArrayObject();
    }

    public function testARowHoldsEveryColumn(): void
    {
        self::assertTrue($this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', '/convert', 200, 12, false));
        self::assertTrue($this->requestLog()->record(self::NOW + 1, '2001:db8::1', 'POST', '/bot/message', 403, 0, true));

        self::assertSame([
            ['ts' => self::NOW, 'ip' => '203.0.113.7', 'method' => 'GET', 'path' => '/convert', 'status' => 200, 'latency_ms' => 12, 'blocked' => 0],
            ['ts' => self::NOW + 1, 'ip' => '2001:db8::1', 'method' => 'POST', 'path' => '/bot/message', 'status' => 403, 'latency_ms' => 0, 'blocked' => 1],
        ], $this->rows());
        self::assertSame([], $this->log->getArrayCopy());
    }

    #[DataProvider('paths')]
    public function testThePathIsAsciiWithoutQueryStringAndAtMost200Characters(string $path, string $stored): void
    {
        self::assertSame($stored, RequestLog::storedPath($path));
        self::assertLessThanOrEqual(200, strlen($stored));
        self::assertMatchesRegularExpression('/^[\x21-\x7E]*$/', $stored);

        $this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', $path, 404, 1, false);
        self::assertSame($stored, $this->rows()[0]['path']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        $a199 = '/' . str_repeat('a', 199);
        yield 'plain' => ['/convert', '/convert'];
        yield 'query string' => ['/convert?amount=200&token=secret', '/convert'];
        yield 'empty query' => ['/convert?', '/convert'];
        yield 'fragment' => ['/a#b?c', '/a'];
        yield 'only a query' => ['?x=1', ''];
        yield 'a 200-character ASCII path stays unchanged' => [$a199, $a199];
        yield '201 cut to 200' => ['/' . str_repeat('a', 200), $a199];
        yield 'long with query' => ['/' . str_repeat('b', 300) . '?q=1', '/' . str_repeat('b', 199)];
        yield 'query after 200 bytes' => ['/' . str_repeat('c', 250) . '?secret', '/' . str_repeat('c', 199)];
        yield 'é is percent-encoded' => ['/caf' . "\u{e9}", '/caf%C3%A9'];
        yield 'an invalid byte is percent-encoded' => ["/x\xFFy", '/x%FFy'];
        yield 'a space is percent-encoded' => ['/a b', '/a%20b'];
        yield 'control bytes and DEL are percent-encoded' => ["/\t\x00\x7F", '/%09%00%7F'];
        yield 'existing escapes are kept' => ['/caf%C3%A9', '/caf%C3%A9'];
        // 197 ASCII + "é" (%C3%A9): the cut at 200 would land inside "%C3" or "%A9".
        yield 'cut would split the 1st escape after its %' => ['/' . str_repeat('d', 198) . "\u{e9}", '/' . str_repeat('d', 198)];
        yield 'cut would split the 1st escape after %C' => ['/' . str_repeat('d', 197) . "\u{e9}", '/' . str_repeat('d', 197)];
        // Round 3: no partial UTF-8 character either; the cut backs off to its first escape.
        yield 'cut would leave half of é' => ['/' . str_repeat('d', 194) . "\u{e9}", '/' . str_repeat('d', 194)];
        yield 'cut right after an escape keeps it' => ['/' . str_repeat('d', 193) . "\u{e9}", '/' . str_repeat('d', 193) . '%C3%A9'];
        yield 'cut would leave 2 of the 3 bytes of €' => ['/' . str_repeat('d', 193) . "\u{20ac}", '/' . str_repeat('d', 193)];
        yield 'cut would leave 1 of the 3 bytes of €' => ['/' . str_repeat('d', 196) . "\u{20ac}", '/' . str_repeat('d', 196)];
        yield 'cut after the whole € keeps it' => ['/' . str_repeat('d', 190) . "\u{20ac}x", '/' . str_repeat('d', 190) . '%E2%82%AC'];
        yield 'cut would leave 3 of the 4 bytes of an emoji' => ['/' . str_repeat('d', 190) . "\u{1F600}", '/' . str_repeat('d', 190)];
        yield 'cut after a whole é before another' => ['/' . str_repeat('d', 190) . "\u{e9}\u{e9}", '/' . str_repeat('d', 190) . '%C3%A9'];
        yield 'a lone invalid byte at the cut is kept' => ['/' . str_repeat('d', 196) . "\xFF\xFF", '/' . str_repeat('d', 196) . '%FF'];
        yield 'a client-escaped partial character is backed off too' => ['/' . str_repeat('d', 194) . '%C3%A9', '/' . str_repeat('d', 194)];
        yield 'a client escape at the cut is not split either' => ['/' . str_repeat('d', 198) . '%41', '/' . str_repeat('d', 198)];
        yield 'a lone % at the cut is kept' => ['/' . str_repeat('d', 198) . '%zz', '/' . str_repeat('d', 198) . '%'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', 'GET'];
        yield 'exactly 16' => [str_repeat('X', 16), str_repeat('X', 16)];
        yield 'cut to 16' => [str_repeat('X', 40), str_repeat('X', 16)];
        yield 'non-ASCII is percent-encoded' => ["G\u{c9}T", 'G%C3%89T'];
        yield 'a space is percent-encoded' => ['GE T', 'GE%20T'];
        yield 'the cut never splits an escape' => [str_repeat('M', 14) . "\xFF", str_repeat('M', 14)];
    }

    #[DataProvider('methods')]
    public function testTheMethodIsAsciiAndAtMost16Characters(string $method, string $stored): void
    {
        self::assertSame($stored, RequestLog::storedMethod($method));

        $this->requestLog()->record(self::NOW, '203.0.113.7', $method, '/', 404, 1, false);
        self::assertSame($stored, $this->rows()[0]['method']);
    }

    public function testStoredValuesAreValidUtf8AndEncodeAsJson(): void
    {
        $path = RequestLog::storedPath('/' . str_repeat("\u{e9}", 150));

        self::assertTrue(mb_check_encoding($path, 'UTF-8'));
        self::assertSame('"' . $path . '"', json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertSame('/' . str_repeat('%C3%A9', 33), $path);
    }

    public function testTheIpIsBounded(): void
    {
        $this->requestLog()->record(self::NOW, str_repeat('9', 100), 'GET', '/', 404, 1, false);

        self::assertSame(str_repeat('9', RequestLog::MAX_IP), $this->rows()[0]['ip']);
    }

    public function testANegativeLatencyIsStoredAsZero(): void
    {
        $this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', '/', 200, -5, false);

        self::assertSame(0, $this->rows()[0]['latency_ms']);
    }

    public function testAFailedWriteIsSwallowedAndNotedWithTheClassOnly(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $log = new RequestLog(Connection::wrap(TestEnvironment::connectReadOnly($path)), $this->noter());

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/secret-path?token=abc', 200, 1, false));

        self::assertSame(0, (int) TestEnvironment::connect($path)->query('SELECT COUNT(*) FROM requests')->fetchColumn());
        self::assertSame(['fx-installments request log: request not recorded (PDOException)'], $this->log->getArrayCopy());
    }

    public function testADatabaseThatCantBeOpenedIsSwallowedToo(): void
    {
        $log = new RequestLog(new Connection(static fn (): PDO => throw new RuntimeException('cannot open C:\\secret')), $this->noter());

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/', 200, 1, false));
        self::assertSame(['fx-installments request log: request not recorded (RuntimeException)'], $this->log->getArrayCopy());
    }

    public function testAFailingFallbackLoggerIsSwallowedToo(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $log = new RequestLog(Connection::wrap(TestEnvironment::connectReadOnly($path)), static function (): void {
            throw new RuntimeException('log down');
        });

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/', 200, 1, false));
    }

    public function testOtherClientErrorsAreUnblocked4xxExcept429SinceTheGivenTime(): void
    {
        $log = $this->requestLog();
        $ip = '203.0.113.7';
        foreach ([
            [self::NOW - 1, 404, false],   // before $since
            [self::NOW, 404, false],
            [self::NOW + 5, 400, false],
            [self::NOW + 5, 401, false],
            [self::NOW + 5, 499, false],
            [self::NOW + 5, 429, false],   // 429s are rate-limited minutes, counted elsewhere
            [self::NOW + 5, 404, true],    // blocked: never counted
            [self::NOW + 5, 403, true],
            [self::NOW + 5, 500, false],   // 5xx: not a client error
            [self::NOW + 5, 503, false],
            [self::NOW + 5, 200, false],
            [self::NOW + 5, 399, false],
        ] as [$ts, $status, $blocked]) {
            $log->record($ts, $ip, 'GET', '/', $status, 1, $blocked);
        }
        $log->record(self::NOW, '198.51.100.1', 'GET', '/', 404, 1, false);

        self::assertSame(4, $log->otherClientErrorsSince($ip, self::NOW));
        self::assertSame(5, $log->otherClientErrorsSince($ip, self::NOW - 1));
        self::assertSame(3, $log->otherClientErrorsSince($ip, self::NOW + 1));
        self::assertSame(0, $log->otherClientErrorsSince($ip, self::NOW + 6));
        self::assertSame(0, $log->otherClientErrorsSince('192.0.2.1', 0));
    }

    private function requestLog(): RequestLog
    {
        return new RequestLog(Connection::wrap($this->pdo), $this->noter());
    }

    private function noter(): \Closure
    {
        $log = $this->log;

        return static function (string $line) use ($log): void {
            $log[] = $line;
        };
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'ts' => (int) $row['ts'],
                'ip' => $row['ip'],
                'method' => $row['method'],
                'path' => $row['path'],
                'status' => (int) $row['status'],
                'latency_ms' => (int) $row['latency_ms'],
                'blocked' => (int) $row['blocked'],
            ],
            $this->pdo->query('SELECT ts, ip, method, path, status, latency_ms, blocked FROM requests ORDER BY id')->fetchAll(),
        );
    }
}
