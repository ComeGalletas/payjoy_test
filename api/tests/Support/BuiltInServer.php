<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * `php -S` on a free loopback port, for end-to-end tests over a real socket. The test is
 * skipped when no loopback port can be bound.
 *
 * The server runs with APP_ENV=test (so it never reads .env), an empty ADMIN_TOKEN and
 * EXCHANGERATE_API_KEY, the given database, and ADR 0018's gate flags (`allow_url_fopen=0`,
 * curl_exec and curl_multi_exec disabled) unless $ini overrides them, so it can't reach the
 * network either. Its stdout and stderr, where error_log lines go, are in log().
 */
final class BuiltInServer
{
    private const STARTUP_SECONDS = 10;

    /** @var resource|null */
    private $process;

    private function __construct($process, public readonly int $port, private readonly string $logPath)
    {
        $this->process = $process;
    }

    /**
     * @param array<string, string> $ini extra `-d` settings, name => value
     * @param string|null $router the router script; defaults to api/public/index.php
     * @param array<string, string> $env extra environment variables
     */
    public static function start(string $databasePath, array $ini = [], ?string $router = null, array $env = []): self
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($probe === false) {
            Assert::markTestSkipped("Cannot bind a loopback port: {$errorMessage}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $public = dirname(__DIR__, 2) . '/public';
        $router ??= $public . '/index.php';
        $log = TestEnvironment::tempDir('e2e-log') . '/server.log';
        $environment = array_replace(getenv(), [
            'APP_ENV' => 'test',
            'DB_DSN' => 'sqlite:' . $databasePath,
            'ADMIN_TOKEN' => '',
            'EXCHANGERATE_API_KEY' => '',
        ], $env);

        $command = [PHP_BINARY];
        foreach (array_replace(['allow_url_fopen' => '0', 'disable_functions' => 'curl_exec,curl_multi_exec'], $ini) as $name => $value) {
            $command[] = '-d';
            $command[] = "{$name}={$value}";
        }
        array_push($command, '-S', "127.0.0.1:{$port}", '-t', dirname($router), $router);

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($router),
            $environment,
        );
        Assert::assertIsResource($process, 'could not start php -S');
        fclose($pipes[0]);
        $server = new self($process, $port, $log);

        $deadline = microtime(true) + self::STARTUP_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            if (!proc_get_status($process)['running']) {
                $output = $server->log();
                $server->stop();
                if (stripos($output, 'Failed to listen') !== false) {
                    Assert::markTestSkipped("php -S could not bind 127.0.0.1:{$port}: " . trim($output));
                }
                Assert::fail('php -S exited early: ' . trim($output));
            }
            usleep(100_000);
        }
        $server->stop();
        Assert::fail('php -S did not start listening within ' . self::STARTUP_SECONDS . ' s');
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function log(): string
    {
        return (string) @file_get_contents($this->logPath);
    }

    /**
     * One HTTP/1.0 request.
     *
     * @param array<string, string> $headers
     * @return array{int, array<string, list<string>>, string} status, lowercase header name => every value, body
     */
    public function request(string $method, string $target, array $headers = [], string $body = ''): array
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errorCode, $errorMessage, 5);
        Assert::assertNotFalse($socket, "connect failed: {$errorMessage}");
        stream_set_timeout($socket, 10);
        $head = "{$method} {$target} HTTP/1.0\r\nHost: 127.0.0.1:{$this->port}\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        if ($body !== '') {
            $head .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        fwrite($socket, $head . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$responseHead, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $responseHead);
        Assert::assertMatchesRegularExpression('#^HTTP/1\.[01] \d{3}#', $lines[0] ?? '', 'no HTTP status line');
        $status = (int) substr($lines[0], 9, 3);
        $received = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $received[strtolower(trim($name))][] = trim($value);
        }

        return [$status, $received, $responseBody];
    }
}
