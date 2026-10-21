<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Starts `php -S` on a free loopback port with public/index.php as the router script and
 * requests an unknown path over a real socket. Skipped when no loopback port can be bound.
 *
 * The server runs with APP_ENV=test (so it never reads .env) and its own temp database.
 */
final class EntryPointEndToEndTest extends TestCase
{
    private const STARTUP_SECONDS = 10;

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    public function testAnUnknownPathReturnsTheNotFoundEnvelope(): void
    {
        $databasePath = TestEnvironment::tempDir('e2e-db') . '/e2e.db';
        $port = $this->startServer($databasePath);

        [$status, $headers, $body] = self::get($port, '/no/such/route?token=abc');

        self::assertSame(404, $status);
        self::assertStringStartsWith('application/json', $headers['content-type'] ?? '');
        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message_for_user' => 'Not found.']],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );

        // index.php applied the schema to the configured database and recorded the error.
        $event = TestEnvironment::connect($databasePath)->query('SELECT error_code, meta FROM events')->fetch();
        self::assertSame('NOT_FOUND', $event['error_code']);
        self::assertSame('{"http_status":404,"method":"GET","path":"/no/such/route"}', $event['meta']);
    }

    private function startServer(string $databasePath): int
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($probe === false) {
            self::markTestSkipped("Cannot bind a loopback port: {$errorMessage}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $public = dirname(__DIR__, 3) . '/public';
        $log = TestEnvironment::tempDir('e2e-log') . '/server.log';
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $databasePath;
        $env['ADMIN_TOKEN'] = '';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $public, $public . '/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $public,
            $env,
        );
        self::assertIsResource($process, 'could not start php -S');
        fclose($pipes[0]);
        $this->server = $process;

        $deadline = microtime(true) + self::STARTUP_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
            if (!proc_get_status($process)['running']) {
                $output = (string) @file_get_contents($log);
                if (stripos($output, 'Failed to listen') !== false) {
                    self::markTestSkipped("php -S could not bind 127.0.0.1:{$port}: " . trim($output));
                }
                self::fail('php -S exited early: ' . trim($output));
            }
            usleep(100_000);
        }
        self::fail('php -S did not start listening within ' . self::STARTUP_SECONDS . ' s');
    }

    /**
     * @return array{int, array<string, string>, string} status, lowercase headers, body
     */
    private static function get(int $port, string $target): array
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 5);
        self::assertNotFalse($socket, "connect failed: {$errorMessage}");
        stream_set_timeout($socket, 10);
        fwrite($socket, "GET {$target} HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        self::assertMatchesRegularExpression('#^HTTP/1\.[01] \d{3}#', $lines[0] ?? '', 'no HTTP status line');
        $status = (int) substr($lines[0], 9, 3);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$status, $headers, $body];
    }
}
