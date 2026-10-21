<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use ArrayObject;
use Closure;
use FxInstallments\Convert\UsdCents;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MetricsTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->log = new ArrayObject();
    }

    public function testRecordWritesOneRowWithEpochTimestampAndJsonMeta(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());

        $written = $this->metrics(Connection::wrap($pdo))->record('bot.step', [
            'source' => 'bot',
            'channel' => 'cli',
            'session_id' => 'abc123',
            'step' => 'amount',
            'currency' => 'BRL',
            'country' => 'Brazil',
            'amount_usd_cents' => 20050,
            'installments' => 3,
            'latency_ms' => 12,
            'meta' => ['reason' => 'unclear', 'text' => 'ação', 'ratio' => 1.0],
        ]);

        self::assertTrue($written);
        self::assertSame([[
            'id' => 1,
            'ts' => 1_790_000_123,
            'source' => 'bot',
            'channel' => 'cli',
            'event_type' => 'bot.step',
            'session_id' => 'abc123',
            'step' => 'amount',
            'currency' => 'BRL',
            'country' => 'Brazil',
            'amount_usd_cents' => 20050,
            'installments' => 3,
            'status' => 'ok',
            'error_code' => null,
            'latency_ms' => 12,
            'meta' => '{"reason":"unclear","text":"ação","ratio":1.0}',
        ]], $pdo->query('SELECT * FROM events')->fetchAll());
        self::assertCount(0, $this->log);
    }

    public function testAmountsAreStoredAsExactIntegerCents(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $metrics = $this->metrics(Connection::wrap($pdo));

        self::assertTrue($metrics->record('api.request', ['amount_usd_cents' => UsdCents::fromCanonical('9999999.99')]));
        self::assertTrue($metrics->record('api.request', ['amount_usd_cents' => null]));

        self::assertSame(
            [['amount_usd_cents' => 999_999_999, 'type' => 'integer'], ['amount_usd_cents' => null, 'type' => 'null']],
            $pdo->query('SELECT amount_usd_cents, typeof(amount_usd_cents) AS type FROM events ORDER BY id')->fetchAll(),
        );
        self::assertCount(0, $this->log);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function nonIntegerValues(): iterable
    {
        yield 'float amount' => ['amount_usd_cents', 200.5];
        yield 'whole float amount' => ['amount_usd_cents', 20050.0];
        yield 'numeric string amount' => ['amount_usd_cents', '20050'];
        yield 'decimal string amount' => ['amount_usd_cents', '200.50'];
        yield 'bool amount' => ['amount_usd_cents', true];
        yield 'the old amount_usd column' => ['amount_usd', 200.5];
        yield 'float installments' => ['installments', 3.0];
        yield 'string latency' => ['latency_ms', '12'];
        yield 'float in a text column' => ['currency', 1.5];
    }

    #[DataProvider('nonIntegerValues')]
    public function testNonIntegerAmountsAndFloatsAreRejected(string $field, mixed $value): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());

        self::assertFalse($this->metrics(Connection::wrap($pdo))->record('api.request', [$field => $value]));

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertSame(['fx-installments metrics: event api.request not recorded (InvalidArgumentException)'], $this->log->getArrayCopy());
    }

    public function testFloatsInsideMetaAreStillAllowed(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());

        self::assertTrue($this->metrics(Connection::wrap($pdo))->record('api.request', ['meta' => ['rate' => 5.2291]]));
        self::assertSame('{"rate":5.2291}', $pdo->query('SELECT meta FROM events')->fetchColumn());
    }

    public function testErrorEventsDefaultToErrorStatus(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());

        self::assertTrue($this->metrics(Connection::wrap($pdo))->error('UPSTREAM_TIMEOUT', ['source' => 'upstream']));

        self::assertSame(
            ['event_type' => 'error', 'source' => 'upstream', 'status' => 'error', 'error_code' => 'UPSTREAM_TIMEOUT', 'meta' => null],
            $pdo->query('SELECT event_type, source, status, error_code, meta FROM events')->fetch(),
        );
    }

    public function testAReadOnlyDatabaseIsSwallowedAndNoted(): void
    {
        $path = TestEnvironment::freshDatabasePath();

        $written = $this->metrics(Connection::wrap(TestEnvironment::connectReadOnly($path)))
            ->record('api.request', ['meta' => ['secret' => 'value-that-must-not-be-logged']]);

        self::assertFalse($written);
        self::assertCount(1, $this->log);
        self::assertSame('fx-installments metrics: event api.request not recorded (PDOException)', $this->log[0]);
        self::assertSame(0, (int) TestEnvironment::connect($path)->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testADatabaseThatCannotBeOpenedIsSwallowed(): void
    {
        $connection = new Connection(static function (): PDO {
            throw new RuntimeException('cannot open sqlite:C:/secret/path.db');
        });

        self::assertFalse($this->metrics($connection)->record('api.request'));
        self::assertSame('fx-installments metrics: event api.request not recorded (RuntimeException)', $this->log[0]);
    }

    public function testAMissingTableIsSwallowed(): void
    {
        $connection = Connection::wrap(Database::connect('sqlite::memory:'));

        self::assertFalse($this->metrics($connection)->record('api.request'));
        self::assertCount(1, $this->log);
    }

    public function testInvalidFieldsAreRejectedWithoutThrowing(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $metrics = $this->metrics(Connection::wrap($pdo));

        self::assertFalse($metrics->record('api.request', ['ip' => '198.51.100.1']));
        self::assertFalse($metrics->record('api.request', ['source' => 'web']));
        self::assertFalse($metrics->record('api.request', ['channel' => 'sms']));
        self::assertFalse($metrics->record('api.request', ['status' => 'maybe']));
        self::assertFalse($metrics->record('api.request', ['meta' => 'not an array']));
        self::assertFalse($metrics->error('NOT_A_CODE'));
        self::assertFalse($metrics->record(''));

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertCount(7, $this->log);
    }

    public function testAFailingFallbackLogIsSwallowedToo(): void
    {
        $metrics = new Metrics(
            Connection::wrap(Database::connect('sqlite::memory:')),
            null,
            static function (): void {
                throw new RuntimeException('log is down');
            },
        );

        self::assertFalse($metrics->record('api.request'));
    }

    public function testTheLogNoteNeverEchoesUnsafeEventTypes(): void
    {
        $this->metrics(Connection::wrap(Database::connect('sqlite::memory:')))->record("evil\ntype key=abc");

        self::assertSame('fx-installments metrics: event evil?type?key?abc not recorded (InvalidArgumentException)', $this->log[0]);
    }

    private function metrics(Connection $connection): Metrics
    {
        $log = $this->log;

        return new Metrics(
            $connection,
            Closure::fromCallable(new FakeClock(1_790_000_123)),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
        );
    }
}
