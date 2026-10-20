<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the hooks in .githooks/ through a POSIX sh and checks what they accept and reject.
 * Skipped with a message when no sh is found, except on CI (CI env var set), where it fails.
 */
final class GitHookTest extends GuardTestCase
{
    private const SCISSORS = '# ------------------------ >8 ------------------------';

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
        yield 'trailing space after summary' => ["P1-05: Add convert \n"];
        yield 'CRLF line endings' => ["P0-04: Add skeleton\r\n\r\nBody\r\n"];
        yield 'leading comment and blank lines' => ["# Please enter the commit message\n\nP0-04: x\n"];
        yield 'git commit -v scissors' => [
            "P0-04: Add skeleton\n\n# Please enter the commit message\n" . self::SCISSORS . "\n"
            . "# Do not modify or remove the line above.\n# Everything below it will be ignored.\n"
            . "diff --git a/x b/x\n+bad message\n",
        ];
        yield 'merge branch' => ["Merge branch 'main' into chore/p0-04-skeleton\n"];
        yield 'merge branches' => ["Merge branches 'a' and 'b'\n"];
        yield 'merge remote-tracking branch' => ["Merge remote-tracking branch 'origin/main'\n"];
        yield 'merge pull request' => ["Merge pull request #1 from ComeGalletas/chore/p0-04-skeleton\n"];
        yield 'merge tag' => ["Merge tag 'v1.0'\n"];
        yield 'merge commit' => ["Merge commit 'abc1234'\n"];
        yield 'revert' => ["Revert \"P0-04: Add skeleton\"\n\nThis reverts commit abc.\n"];
        yield 'reapply' => ["Reapply \"P0-04: Add skeleton\"\n\nThis reverts commit def.\n"];
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
        yield 'spaces only summary (verbatim cleanup)' => ["P1-05:  \n"];
        yield 'tab only summary (verbatim cleanup)' => ["P1-05: \t\n"];
        yield 'space and tab summary, CRLF' => ["P1-05: \t \r\n"];
        yield 'no colon' => ["P0-04 Add skeleton\n"];
        yield 'two digit phase' => ["P10-04: x\n"];
        yield 'unknown prefix' => ["X1-04: x\n"];
        yield 'conventional commit' => ["feat: add thing\n"];
        yield 'later with one digit' => ["L-1: x\n"];
        yield 'ID not at start' => [" P0-04: x\n"];
        yield 'Merged is not Merge' => ["Merged stuff\n"];
        yield 'free-form Merge' => ["Merge stuff from yesterday\n"];
        yield 'Merge without a git keyword' => ["Merge 'main'\n"];
        yield 'Revert without quote' => ["Revert P0-04\n"];
        yield 'Reapply without quote' => ["Reapply P0-04\n"];
        yield 'only text below scissors' => [
            "\n# Please enter the commit message\n" . self::SCISSORS . "\n"
            . "# Do not modify or remove the line above.\nP0-04: not part of the message\n",
        ];
    }

    public function testCommitMsgRejectsMissingFile(): void
    {
        [$exitCode, , $stderr] = $this->runHook('commit-msg', [$this->tempFile() . '.missing']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('no commit message file', $stderr);
    }

    #[DataProvider('mainPushes')]
    public function testPrePushRejectsMain(string $stdin, string $expectedRef): void
    {
        // A make that fails loudly proves the main check runs before `make check` is reached.
        $env = ['PATH' => $this->stubMake(1)];

        [$exitCode, $stdout, $stderr] = $this->runHook('pre-push', ['origin', 'https://example.invalid/r.git'], $stdin, $env);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("rejected push of {$expectedRef} to origin main", $stderr);
        self::assertStringContainsString('GR-013', $stderr);
        self::assertStringNotContainsString('running make check', $stdout);
        self::assertStringNotContainsString('stub make', $stdout);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mainPushes(): iterable
    {
        $zero = str_repeat('0', 40);
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);

        yield 'update main' => ["refs/heads/feature {$a} refs/heads/main {$zero}\n", 'refs/heads/feature'];
        yield 'main among other refs, no final newline' => [
            "refs/heads/a {$b} refs/heads/a {$b}\nrefs/heads/b {$b} refs/heads/main {$b}",
            'refs/heads/b',
        ];
        yield 'delete main' => ["(delete) {$zero} refs/heads/main {$a}\n", '(delete)'];
    }

    public function testPrePushRunsMakeCheckForTagPush(): void
    {
        $sha = str_repeat('c', 40);
        $zero = str_repeat('0', 40);
        $env = ['PATH' => $this->stubMake(0)];

        [$exitCode, $stdout, $stderr] = $this->runHook(
            'pre-push',
            ['origin', 'x'],
            "refs/tags/v1.0 {$sha} refs/tags/v1.0 {$zero}\n",
            $env,
        );

        self::assertSame(0, $exitCode, "a tag push must not hit the main rule; stderr: {$stderr}");
        self::assertStringNotContainsString('rejected push', $stderr);
        self::assertStringContainsString('running make check', $stdout);
        self::assertStringContainsString('stub make check', $stdout);
    }

    public function testPrePushBlocksWhenMakeCheckFails(): void
    {
        $sha = str_repeat('d', 40);
        $zero = str_repeat('0', 40);
        $env = ['PATH' => $this->stubMake(1)];

        [$exitCode, $stdout, $stderr] = $this->runHook(
            'pre-push',
            ['origin', 'x'],
            "refs/heads/chore/x {$sha} refs/heads/chore/x {$zero}\n",
            $env,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('stub make check', $stdout);
        self::assertStringContainsString('make check failed; push blocked', $stderr);
    }

    /**
     * A directory holding a `make` stub that prints its arguments and exits with $exitCode.
     * The pre-push tests use this directory as the whole PATH (the hook needs only shell
     * builtins and make), so the real `make check` can never run from inside the test suite.
     */
    private function stubMake(int $exitCode): string
    {
        $dir = $this->tempDir();
        $stub = $dir . '/make';
        file_put_contents($stub, "#!/bin/sh\necho \"stub make \$*\"\nexit {$exitCode}\n");
        chmod($stub, 0755);

        return PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $dir) : $dir;
    }

    /**
     * @return array{int, string, string}
     */
    private function runCommitMsg(string $message): array
    {
        return $this->runHook('commit-msg', [$this->tempFile($message)]);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|null> $env
     * @return array{int, string, string}
     */
    private function runHook(string $hook, array $arguments, string $stdin = '', array $env = []): array
    {
        $sh = $this->requireSh();
        $path = self::repoRoot() . '/.githooks/' . $hook;
        self::assertFileExists($path);

        return self::runProcess(
            [$sh, self::toForwardSlashes($path), ...array_map(self::toForwardSlashes(...), $arguments)],
            $stdin,
            null,
            $env,
        );
    }
}
