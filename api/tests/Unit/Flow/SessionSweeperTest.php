<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Flow\Collected;
use FxInstallments\Flow\FlowDefinitionError;
use FxInstallments\Flow\SessionStore;
use FxInstallments\Flow\SessionSweeper;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\HookedPdo;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The expiry sweep (docs/PLAN.md §9), without the flow file and in one transaction.
 */
final class SessionSweeperTest extends TestCase
{
    private const NOW = 1_792_544_401;

    private string $path;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testItSweepsWithABrokenFlowFile(): void
    {
        $broken = TestEnvironment::tempDir('flow') . '/default.yaml';
        file_put_contents($broken, "name: [\n");
        $log = new ArrayObject();
        $app = new App(
            new ConfigRepository(),
            Connection::wrap($this->pdo),
            Closure::fromCallable(new FakeClock(self::NOW)),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            (new MockUpstream())->client(),
            $broken,
        );
        $session = $this->store()->create('cli', 'currency', self::NOW - 1800);
        $this->pdo->prepare('UPDATE sessions SET collected = ? WHERE session_id = ?')->execute([(new Collected())->withAmount(20_000)->toJson(), $session->id]);

        self::assertSame(1, $app->sessionSweeper()->sweep());

        self::assertSame(
            [['bot.session_abandoned', 'bot', 'cli', $session->id, 'currency', 'BOT_SESSION_EXPIRED', 20_000, '{"expires_at":' . self::NOW . '}']],
            $this->pdo->query('SELECT event_type, source, channel, session_id, step, error_code, amount_usd_cents, meta FROM events')->fetchAll(PDO::FETCH_NUM),
        );
        $this->expectException(FlowDefinitionError::class);
        $app->flowEngine();
    }

    public function testOneSweepIsOneTransaction(): void
    {
        foreach ([1800, 1900, 2000] as $age) {
            $this->store()->create('static', 'amount', self::NOW - $age);
        }
        $hooked = HookedPdo::open($this->path);

        self::assertSame(3, $this->sweeper($hooked)->sweep(self::NOW));

        $writes = array_values(array_filter($hooked->prepared, static fn (array $statement): bool => in_array($statement[0], ['SELECT', 'UPDATE', 'INSERT'], true)));
        self::assertSame([['SELECT', true], ['UPDATE', true], ['INSERT', true], ['UPDATE', true], ['INSERT', true], ['UPDATE', true], ['INSERT', true]], $writes);
        self::assertFalse($hooked->inTransaction());
    }

    public function testAFailureRollsTheWholeSweepBack(): void
    {
        $first = $this->store()->create('static', 'amount', self::NOW - 2000);
        $second = $this->store()->create('static', 'amount', self::NOW - 1900);
        $this->pdo->exec("CREATE TRIGGER no_close BEFORE UPDATE ON sessions WHEN OLD.session_id = '{$second->id}' BEGIN SELECT RAISE(ABORT, 'no'); END");

        try {
            $this->sweeper($this->pdo)->sweep(self::NOW);
            self::fail('the failure is rethrown');
        } catch (PDOException) {
            // expected
        }

        self::assertSame(['open', 'open'], [$this->sessionStatus($first->id), $this->sessionStatus($second->id)]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testALockedDatabaseSkipsTheSweep(): void
    {
        $session = $this->store()->create('static', 'amount', self::NOW - 1800);
        $other = TestEnvironment::connect($this->path);
        $other->exec('BEGIN IMMEDIATE');
        $mine = TestEnvironment::connect($this->path);
        $mine->exec('PRAGMA busy_timeout = 0');

        self::assertSame(0, $this->sweeper($mine)->sweep(self::NOW), 'another writer holds the lock: housekeeping waits');
        self::assertFalse($mine->inTransaction());

        $other->exec('COMMIT');
        self::assertSame('open', $this->sessionStatus($session->id));
        self::assertSame(1, $this->sweeper($mine)->sweep(self::NOW));
        self::assertSame('abandoned', $this->sessionStatus($session->id));
    }

    public function testCloseOneRecordsOnce(): void
    {
        $session = $this->store()->create('typebot', 'installments', self::NOW - 1800);
        $sweeper = $this->sweeper($this->pdo);

        self::assertTrue($sweeper->closeOne($session, self::NOW));
        self::assertFalse($sweeper->closeOne($session, self::NOW));
        self::assertSame(0, $sweeper->sweep(self::NOW));
        self::assertSame([['installments', 'typebot']], $this->pdo->query('SELECT step, channel FROM events')->fetchAll(PDO::FETCH_NUM));
    }

    private function store(?PDO $pdo = null): SessionStore
    {
        return new SessionStore(Connection::wrap($pdo ?? $this->pdo), 30);
    }

    private function sweeper(PDO $pdo): SessionSweeper
    {
        $log = new ArrayObject();

        return new SessionSweeper(
            $this->store($pdo),
            new Metrics(Connection::wrap($pdo), static fn (): int => self::NOW, static function (string $line) use ($log): void {
                $log[] = $line;
            }),
            static fn (): int => self::NOW,
        );
    }

    private function sessionStatus(string $id): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM sessions WHERE session_id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }
}
