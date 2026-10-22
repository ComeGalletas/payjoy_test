<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use FxInstallments\Db\Connection;
use FxInstallments\Flow\BotCli;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `php bin/bot.php`, run as a separate process on a temp database whose stored rate table is
 * fresh, so the conversion is a cache hit and no upstream call is made (the test checks the
 * `upstream.call` event says `hit`). The process can't load the test bootstrap's guards, so it
 * runs with the gate scripts' flags (`-d allow_url_fopen=0 -d disable_functions=curl_exec,
 * curl_multi_exec`, ADR 0018): Guzzle has no transport at all, which works because the rate
 * cache builds the upstream client only on a refresh. A canary key and HTTP(S)_PROXY on a
 * closed loopback port are an extra layer. And BotCli in-process for what a pipe can't show
 * (the interactive prompt).
 */
final class BotCliTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
    }

    public function testAPipedSessionPrintsTheConversation(): void
    {
        $lastUpdate = time() - 60;
        $db = Connection::wrap(TestEnvironment::connect($this->path));
        (new RateStore($db))->replace(new RateTable(['USD' => 10_000_000_000, 'BRL' => 52_291_000_000], $lastUpdate, time() + 86_400, $lastUpdate));

        [$exit, $out, $err] = $this->runCli([], "200\nbrazil\r\n3\n");

        self::assertSame('', $err);
        self::assertSame(0, $exit);
        self::assertSame(
            "Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency.\n"
            . "What was the total price in USD?\n"
            . "Which currency or country should I convert to?\n"
            . "[BRL] [COP] [MXN] [EUR]\n"
            . "How many monthly installments? (default 1)\n"
            . "[Just one payment]\n"
            . '200.00 USD is about 1,045.82 BRL today (rate 5.2291, as of ' . gmdate('Y-m-d H:i', $lastUpdate) . ' UTC).'
            . " That's 2 monthly payments of 348.60 BRL and a final payment of 348.62 BRL, so the total is exactly 1,045.82 BRL.\n",
            $out,
        );

        $pdo = TestEnvironment::connect($this->path);
        self::assertSame(
            ['bot.session_start', 'bot.step', 'bot.step', 'upstream.call', 'bot.step', 'bot.session_completed'],
            $pdo->query('SELECT event_type FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(['cli'], array_values(array_unique($pdo->query("SELECT channel FROM events WHERE source = 'bot'")->fetchAll(PDO::FETCH_COLUMN))));
        self::assertStringContainsString('"result":"hit"', (string) $pdo->query("SELECT meta FROM events WHERE event_type = 'upstream.call'")->fetchColumn());
    }

    public function testInputEndingFirstExitsOne(): void
    {
        [$exit, $out] = $this->runCli([], "200\n");

        self::assertSame(1, $exit);
        self::assertStringEndsWith("Which currency or country should I convert to?\n[BRL] [COP] [MXN] [EUR]\n", $out);
    }

    public function testAnAbandonedSessionExitsOne(): void
    {
        [$exit, $out] = $this->runCli([], "x\ny\nz\n");

        self::assertSame(1, $exit);
        self::assertStringEndsWith("Sorry, I couldn't follow that. Send me a new message whenever you'd like to start again.\n", $out);
    }

    public function testArgumentsPrintUsage(): void
    {
        [$exit, $out, $err] = $this->runCli(['--help'], '');

        self::assertSame([2, '', "usage: php bin/bot.php\n"], [$exit, $out, $err]);
    }

    public function testADatabaseBehindTheCodeIsRefused(): void
    {
        $path = TestEnvironment::tempDir('bot-cli-empty') . '/empty.db';
        touch($path);

        [$exit, $out, $err] = $this->runCli([], "200\n", $path);

        self::assertSame([1, '', "bot: failed: FxInstallments\\Db\\SchemaOutOfDate\n"], [$exit, $out, $err]);
    }

    public function testAnInteractiveRunPromptsForEachAnswer(): void
    {
        $engine = TestEnvironment::app(new FakeClock(MockUpstream::LAST_UPDATE), [], TestEnvironment::connect($this->path), upstream: new MockUpstream())->flowEngine();
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($input);
        self::assertIsResource($output);
        fwrite($input, "200\n");
        rewind($input);

        self::assertSame(1, (new BotCli($engine))->run($input, $output, true));

        rewind($output);
        self::assertSame(2, substr_count((string) stream_get_contents($output), "\n> "));
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runCli(array $args, string $stdin, ?string $path = null): array
    {
        $dir = TestEnvironment::tempDir('bot-cli');
        file_put_contents($dir . '/in', $stdin);
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . ($path ?? $this->path);
        $env['EXCHANGERATE_API_KEY'] = MockUpstream::CANARY_KEY;
        $env['HTTPS_PROXY'] = $env['HTTP_PROXY'] = $env['https_proxy'] = $env['http_proxy'] = 'http://127.0.0.1:9';
        unset($env['NO_PROXY'], $env['no_proxy']);
        $process = proc_open(
            [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec', dirname(__DIR__, 3) . '/bin/bot.php', ...$args],
            [0 => ['file', $dir . '/in', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start bin/bot.php');
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
