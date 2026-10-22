<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use FxInstallments\Db\Connection;
use FxInstallments\Flow\Collected;
use FxInstallments\Flow\Session;
use FxInstallments\Flow\SessionStore;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    private const NOW = 1_792_544_401;

    private string $path;

    private PDO $pdo;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->store = new SessionStore(Connection::wrap($this->pdo), 30);
    }

    protected function tearDown(): void
    {
        unset($this->store, $this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testCreateIssuesAServerIdAndAnOpenRow(): void
    {
        $session = $this->store->create('static', 'amount', self::NOW);
        $other = $this->store->create('cli', 'amount', self::NOW);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $session->id);
        self::assertNotSame($session->id, $other->id);
        self::assertSame([
            'session_id' => $session->id,
            'channel' => 'static',
            'step' => 'amount',
            'status' => 'open',
            'collected' => '{}',
            'retries' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'expires_at' => self::NOW + 1800,
        ], $this->row($session->id));
        self::assertEquals($session, $this->store->find($session->id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsNeverFound(): iterable
    {
        yield 'unknown' => [str_repeat('a', 32)];
        yield 'uppercase' => [strtoupper(str_repeat('ab', 16))];
        yield 'short' => ['abc'];
        yield 'with a newline' => [str_repeat('a', 32) . "\n"];
        yield 'SQL' => ["' OR 1=1 --"];
        yield 'empty' => [''];
    }

    #[DataProvider('idsNeverFound')]
    public function testMalformedAndUnknownIdsAreNotFound(string $id): void
    {
        $this->store->create('static', 'amount', self::NOW);

        self::assertNull($this->store->find($id));
    }

    public function testADamagedRowIsNotRead(): void
    {
        $session = $this->store->create('static', 'currency', self::NOW);
        $this->pdo->prepare('UPDATE sessions SET collected = ? WHERE session_id = ?')->execute(['{"amount_usd_cents":"200"}', $session->id]);

        self::assertNull($this->store->find($session->id));
    }

    public function testSaveIsACompareAndSet(): void
    {
        $old = $this->store->create('static', 'amount', self::NOW);
        $next = $old->next('currency', Session::OPEN, (new Collected())->withAmount(20_000), 0, self::NOW + 5, $this->store->ttlSeconds);
        $racing = $old->next('amount', Session::OPEN, new Collected(), 1, self::NOW + 5, $this->store->ttlSeconds);

        self::assertTrue($this->store->save($old, $next));
        self::assertFalse($this->store->save($old, $racing), 'the row no longer holds the old state');
        self::assertEquals($next, $this->store->find($old->id));
        self::assertSame(['step' => 'currency', 'collected' => '{"amount_usd_cents":20000}', 'expires_at' => self::NOW + 5 + 1800], array_intersect_key($this->row($old->id), array_flip(['step', 'collected', 'expires_at'])));

        $completed = $next->next('result', Session::COMPLETED, $next->collected, 0, self::NOW + 9, $this->store->ttlSeconds);
        self::assertTrue($this->store->save($next, $completed));
        self::assertSame(self::NOW + 9, $this->row($old->id)['expires_at'], 'a closed session expires now');
        self::assertFalse($this->store->save($completed, $completed), 'a closed session is never saved again');
    }

    public function testTheSweepClosesOpenSessionsAtExpiresAt(): void
    {
        $due = $this->store->create('static', 'currency', self::NOW - 1800);
        $live = $this->store->create('cli', 'amount', self::NOW - 1799);
        $done = $this->store->create('static', 'amount', self::NOW - 4000);
        $this->pdo->exec("UPDATE sessions SET status = 'completed' WHERE session_id = '{$done->id}'");

        $closed = $this->store->sweepExpired(self::NOW);

        self::assertSame([$due->id], array_map(static fn (Session $session): string => $session->id, $closed));
        self::assertSame(['open', 'currency'], [$closed[0]->status, $closed[0]->step], 'returned as it was');
        self::assertSame(['abandoned', 'currency', self::NOW], [$this->row($due->id)['status'], $this->row($due->id)['step'], $this->row($due->id)['updated_at']]);
        self::assertSame('open', $this->row($live->id)['status']);
        self::assertSame('completed', $this->row($done->id)['status']);
        self::assertSame([], $this->store->sweepExpired(self::NOW), 'closed once');
        self::assertFalse($this->store->closeExpired($live->id, self::NOW));
    }

    public function testTheSweepClosesADamagedRowWithNothingCollected(): void
    {
        $session = $this->store->create('static', 'installments', self::NOW - 3600);
        $this->pdo->prepare('UPDATE sessions SET collected = ? WHERE session_id = ?')->execute(['not json', $session->id]);

        $closed = $this->store->sweepExpired(self::NOW);

        self::assertCount(1, $closed);
        self::assertSame(['installments', []], [$closed[0]->step, $closed[0]->collected->eventFields()]);
    }

    public function testTheSweepIsBounded(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO sessions (session_id, channel, step, status, collected, retries, created_at, updated_at, expires_at) VALUES (?, 'static', 'amount', 'open', '{}', 0, 1, 1, 2)");
        for ($i = 0; $i < SessionStore::SWEEP_LIMIT + 1; $i++) {
            $insert->execute([SessionStore::newId()]);
        }

        self::assertCount(SessionStore::SWEEP_LIMIT, $this->store->sweepExpired(self::NOW));
        self::assertCount(1, $this->store->sweepExpired(self::NOW));
    }

    public function testTheTtlMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStore(Connection::wrap($this->pdo), 0);
    }

    /**
     * @return array<string, int|string>
     */
    private function row(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sessions WHERE session_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        foreach (['retries', 'created_at', 'updated_at', 'expires_at'] as $column) {
            $row[$column] = (int) $row[$column];
        }

        return $row;
    }
}
