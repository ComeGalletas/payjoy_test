<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Tests\Support\BuiltInServer;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Starts `php -S` on a free loopback port with public/index.php as the router script and
 * requests an unknown path over a real socket. Skipped when no loopback port can be bound.
 *
 * The server is BuiltInServer's (L-04 cleanup B): APP_ENV=test (so it never reads .env), an
 * empty ADMIN_TOKEN and EXCHANGERATE_API_KEY, its own temp database, and ADR 0018's gate flags.
 */
final class EntryPointEndToEndTest extends TestCase
{
    private ?BuiltInServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testAnUnknownPathReturnsTheNotFoundEnvelope(): void
    {
        // Migrated first, as bin/migrate.php does at install: the app never migrates.
        $databasePath = TestEnvironment::freshDatabasePath();
        $this->server = BuiltInServer::start($databasePath);

        [$status, $headers, $body] = $this->server->request('GET', '/no/such/route?token=abc');

        self::assertSame(404, $status, $this->server->log());
        self::assertStringStartsWith('application/json', $headers['content-type'][0] ?? '');
        self::assertSame(['nosniff'], $headers['x-content-type-options'] ?? null);
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message_for_user' => 'Not found.']],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );

        // index.php used the configured database and recorded the error.
        $event = TestEnvironment::connect($databasePath)->query('SELECT error_code, meta FROM events')->fetch();
        self::assertSame('NOT_FOUND', $event['error_code']);
        self::assertSame('{"http_status":404,"method":"GET","path":"/no/such/route"}', $event['meta']);

        // ...and wrote the request log row (P1-11): peer address, no query string.
        self::assertSame(
            [['ip' => '127.0.0.1', 'method' => 'GET', 'path' => '/no/such/route', 'status' => 404, 'blocked' => 0]],
            array_map(
                static fn (array $row): array => array_replace($row, ['status' => (int) $row['status'], 'blocked' => (int) $row['blocked']]),
                TestEnvironment::connect($databasePath)->query('SELECT ip, method, path, status, blocked FROM requests')->fetchAll(),
            ),
        );
    }

    public function testADatabaseBehindTheCodeFailsClosedWithInternal(): void
    {
        // Never migrated: index.php must not apply the schema, and must not route either. (An
        // empty file: a missing one is refused before opening, L_04_CleanupATest.)
        $databasePath = TestEnvironment::tempDir('e2e-db') . '/behind.db';
        touch($databasePath);
        $this->server = BuiltInServer::start($databasePath);

        [$status, $headers, $body] = $this->server->request('GET', '/no/such/route');

        self::assertSame(500, $status, $this->server->log());
        self::assertStringStartsWith('application/json', $headers['content-type'][0] ?? '');
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'INTERNAL', 'message_for_user' => 'Something went wrong on our side. Please try again later.']],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame([], TestEnvironment::connect($databasePath)->query('SELECT name FROM sqlite_master')->fetchAll());
        self::assertStringContainsString(
            'fx-installments INTERNAL before routing: FxInstallments\Db\SchemaOutOfDate at ',
            $this->server->log(),
        );
    }
}
