<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the hooks in .githooks/ through a POSIX sh and checks what they accept and reject.
 * Skipped, with a message saying why, only when no sh can be found.
 */
final class GitHookTest extends TestCase
{
    private static ?string $sh = null;
    private static bool $shSearched = false;

    private string $hooksDir;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $sh = self::locateSh();
        if ($sh === null) {
            self::markTestSkipped(
                'No POSIX sh found (looked on PATH, next to the git install and in Git for Windows '
                . 'defaults); git hook guard tests not run.',
            );
        }
        $this->hooksDir = dirname(__DIR__, 3) . '/.githooks';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    #[DataProvider('acceptedMessages')]
    public function testCommitMsgAccepts(string $message): void
    {
        [$exitCode, , $stderr] = $this->runCommitMsg($message);

        self::assertSame(0, $exitCode, "commit-msg should accept the message; stderr: {$stderr}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedMessages(): iterable
    {
        yield 'phase task' => ["P0-04: x\n"];
        yield 'phase task with body' => ["P1-03: Add AmountParser\n\nBody line.\n"];
        yield 'later task' => ["L-01: Add Typebot export\n"];
        yield 'two digit task' => ["P3-11: Write ADDING_A_FEATURE.md\n"];
        yield 'CRLF line endings' => ["P0-04: Add skeleton\r\n\r\nBody\r\n"];
        yield 'leading comment and blank lines' => ["# Please enter the commit message\n\nP0-04: x\n"];
        yield 'git merge' => ["Merge branch 'main' into chore/p0-04-skeleton\n"];
        yield 'git merge pull request' => ["Merge pull request #1 from ComeGalletas/chore/p0-04-skeleton\n"];
        yield 'git revert' => ["Revert \"P0-04: Add skeleton\"\n\nThis reverts commit abc.\n"];
    }

    #[DataProvider('rejectedMessages')]
    public function testCommitMsgRejects(string $message): void
    {
        [$exitCode, , $stderr] = $this->runCommitMsg($message);

        self::assertSame(1, $exitCode, 'commit-msg should reject the message');
        self::assertStringContainsString('commit-msg: rejected subject', $stderr);
        self::assertStringContainsString('<ID>: <imperative summary>', $stderr);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedMessages(): iterable
    {
        yield 'no ID' => ["bad message\n"];
        yield 'empty' => [''];
        yield 'only comments' => ["# nothing here\n"];
        yield 'one digit task' => ["P0-4: x\n"];
        yield 'lowercase ID' => ["p0-04: x\n"];
        yield 'no space after colon' => ["P0-04:x\n"];
        yield 'nothing after colon' => ["P0-04: \n"];
        yield 'no colon' => ["P0-04 Add skeleton\n"];
        yield 'two digit phase' => ["P10-04: x\n"];
        yield 'unknown prefix' => ["X1-04: x\n"];
        yield 'conventional commit' => ["feat: add thing\n"];
        yield 'later with one digit' => ["L-1: x\n"];
        yield 'ID not at start' => [" P0-04: x\n"];
        yield 'Merged is not Merge' => ["Merged stuff\n"];
        yield 'Revert without quote' => ["Revert P0-04\n"];
    }

    public function testCommitMsgRejectsMissingFile(): void
    {
        [$exitCode, , $stderr] = $this->runHook([$this->hookPath('commit-msg'), $this->tempPath() . '.missing']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('no commit message file', $stderr);
    }

    public function testPrePushRejectsMain(): void
    {
        $zero = str_repeat('0', 40);
        $sha = str_repeat('a', 40);
        $stdin = "refs/heads/feature {$sha} refs/heads/main {$zero}\n";

        // The main check runs before `make check`, so this never starts the gate.
        [$exitCode, $stdout, $stderr] = $this->runHook(
            [$this->hookPath('pre-push'), 'origin', 'https://example.invalid/repo.git'],
            $stdin,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('rejected push', $stderr);
        self::assertStringContainsString('GR-013', $stderr);
        self::assertStringNotContainsString('running make check', $stdout);
    }

    public function testPrePushRejectsMainAmongOtherRefs(): void
    {
        $sha = str_repeat('b', 40);
        $stdin = "refs/heads/a {$sha} refs/heads/a {$sha}\nrefs/heads/b {$sha} refs/heads/main {$sha}";

        [$exitCode, $stdout, $stderr] = $this->runHook([$this->hookPath('pre-push'), 'origin', 'x'], $stdin);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('rejected push of refs/heads/b', $stderr);
        self::assertStringNotContainsString('running make check', $stdout);
    }

    /**
     * @return array{int, string, string}
     */
    private function runCommitMsg(string $message): array
    {
        $file = $this->tempPath();
        file_put_contents($file, $message);

        return $this->runHook([$this->hookPath('commit-msg'), $file]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runHook(array $arguments, string $stdin = ''): array
    {
        $sh = self::locateSh();
        self::assertNotNull($sh);

        $process = proc_open(
            [$sh, ...array_map(self::toForwardSlashes(...), $arguments)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'could not start sh');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function hookPath(string $name): string
    {
        $path = $this->hooksDir . '/' . $name;
        self::assertFileExists($path);

        return $path;
    }

    private function tempPath(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fxhook');
        self::assertIsString($file);
        $this->tempFiles[] = $file;

        return $file;
    }

    private static function toForwardSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Finds a POSIX sh: SH_PATH if set, then PATH, then (on Windows) Git for Windows' bin/sh.exe
     * next to the git install, then the default install folders.
     */
    private static function locateSh(): ?string
    {
        if (self::$shSearched) {
            return self::$sh;
        }
        self::$shSearched = true;

        $windows = PHP_OS_FAMILY === 'Windows';
        $candidates = [];

        $override = getenv('SH_PATH');
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }

        if ($windows) {
            $gitDir = self::gitInstallDir();
            if ($gitDir !== null) {
                $candidates[] = $gitDir . '\\bin\\sh.exe';
                $candidates[] = $gitDir . '\\usr\\bin\\sh.exe';
            }
            foreach (self::pathDirectories() as $dir) {
                $candidates[] = $dir . '\\sh.exe';
            }
            foreach (['ProgramFiles', 'ProgramW6432', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $var) {
                $base = getenv($var);
                if (is_string($base) && $base !== '') {
                    $candidates[] = $base . '\\Git\\bin\\sh.exe';
                    $candidates[] = $base . '\\Programs\\Git\\bin\\sh.exe';
                }
            }
        } else {
            $candidates[] = '/bin/sh';
            foreach (self::pathDirectories() as $dir) {
                $candidates[] = $dir . '/sh';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && ($windows || is_executable($candidate))) {
                return self::$sh = $candidate;
            }
        }

        return null;
    }

    /**
     * The Git for Windows install root, derived from `git --exec-path`
     * (for example C:/Program Files/Git/mingw64/libexec/git-core).
     */
    private static function gitInstallDir(): ?string
    {
        $process = @proc_open(
            ['git', '--exec-path'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }
        $execPath = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $execPath === '') {
            return null;
        }

        $root = dirname(str_replace('/', '\\', $execPath), 3);

        return is_dir($root) ? $root : null;
    }

    /**
     * @return list<string>
     */
    private static function pathDirectories(): array
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $dir): string => rtrim(trim($dir, " \""), '\\/'), explode(PATH_SEPARATOR, $path)),
            static fn (string $dir): bool => $dir !== '',
        ));
    }
}
