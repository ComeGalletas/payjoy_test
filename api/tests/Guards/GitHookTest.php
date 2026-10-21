<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the hooks in .githooks/ through a POSIX sh and checks what they accept and reject; a few
 * tests make real commits in throwaway repositories with commit-msg installed.
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
        yield 'leading blank lines' => ["\n \n\t\nP0-04: x\n"];
        yield 'comment lines below the subject' => ["P0-04: x\n\n# Please enter the commit message\n# Lines starting\n"];
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
        yield 'only blank lines' => ["\n  \n\t\n"];
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

    /**
     * git runs commit-msg before its cleanup, and the hook can't tell the cleanup mode, so a
     * first non-blank line that starts with the comment character is always rejected: under
     * --cleanup=verbatim (or whitespace, the default for -m and -F) it would be the subject.
     *
     * @param array<string, string> $config
     */
    #[DataProvider('commentFirstMessages')]
    public function testCommitMsgRejectsCommentAsFirstLine(string $message, array $config, string $comment): void
    {
        [$exitCode, , $stderr] = $this->runCommitMsg($message, $config);

        self::assertSame(1, $exitCode, 'commit-msg should reject a comment as the first line');
        self::assertStringContainsString('commit-msg: rejected subject', $stderr);
        self::assertStringContainsString("starts with the comment character \"{$comment}\"", $stderr);
        self::assertStringContainsString('<ID>: <imperative summary>', $stderr);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function commentFirstMessages(): iterable
    {
        yield 'hash, verbatim-style message' => ["# note\nP1-05: x\n", [], '#'];
        yield 'git template comment above subject' => ["# Please enter the commit message\n\nP0-04: x\n", [], '#'];
        yield 'blank lines, then comment' => ["\n \n# note\nP1-05: x\n", [], '#'];
        yield 'only comments' => ["# nothing here\n", [], '#'];
        yield 'CRLF comment' => ["# note\r\nP1-05: x\r\n", [], '#'];
        yield 'commentChar auto means hash' => ["# note\nP1-05: x\n", ['core.commentChar' => 'auto'], '#'];
        yield 'commentChar semicolon' => ["; note\nP1-05: x\n", ['core.commentChar' => ';'], ';'];
        // Not "//": MSYS sh on Windows rewrites that environment value to "/" for native git.
        yield 'commentChar multi-character prefix' => ["%% note\nP1-05: x\n", ['core.commentChar' => '%%'], '%%'];
        yield 'commentChar regex metacharacter' => ["* note\nP1-05: x\n", ['core.commentChar' => '*'], '*'];
        yield 'commentString alias' => ["% note\nP1-05: x\n", ['core.commentString' => '%'], '%'];
    }

    /**
     * @param array<string, string> $config
     */
    #[DataProvider('commentCharAccepted')]
    public function testCommitMsgAcceptsWithCommentChar(string $message, array $config): void
    {
        [$exitCode, , $stderr] = $this->runCommitMsg($message, $config);

        self::assertSame(0, $exitCode, "commit-msg should accept the message; stderr: {$stderr}");
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function commentCharAccepted(): iterable
    {
        $semicolon = ['core.commentChar' => ';'];

        yield 'semicolon comment below subject' => ["P1-05: x\n\n; note\n", $semicolon];
        yield 'hash line in body is text under semicolon' => ["P1-05: x\n\n# kept as text\n", $semicolon];
        yield 'semicolon scissors' => [
            "P1-05: x\n\n; Please enter the commit message\n; ------------------------ >8 ------------------------\n"
            . "; Do not modify or remove the line above.\ndiff --git a/x b/x\n+bad message\n",
            $semicolon,
        ];
        yield 'multi-character prefix below subject' => ["P1-05: x\n\n%% note\n", ['core.commentChar' => '%%']];
        yield 'commentChar auto' => ["P1-05: x\n\n# note\n", ['core.commentChar' => 'auto']];
    }

    /**
     * @param array<string, string> $config
     */
    #[DataProvider('commentCharRejected')]
    public function testCommitMsgRejectsWithCommentChar(string $message, array $config, string $subject): void
    {
        [$exitCode, , $stderr] = $this->runCommitMsg($message, $config);

        self::assertSame(1, $exitCode, 'commit-msg should reject the message');
        self::assertStringContainsString("commit-msg: rejected subject: \"{$subject}\"", $stderr);
        self::assertStringNotContainsString('comment character', $stderr, 'the line is not a comment, so the format check rejects it');
        self::assertStringContainsString('<ID>: <imperative summary>', $stderr);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function commentCharRejected(): iterable
    {
        $semicolon = ['core.commentChar' => ';'];

        yield 'hash is not a comment under semicolon' => ["# P1-05\n", $semicolon, '# P1-05'];
        yield 'hash scissors is text under semicolon' => [
            self::SCISSORS . "\nP1-05: x\n",
            $semicolon,
            self::SCISSORS,
        ];
        yield 'only text below semicolon scissors' => [
            "\n; ------------------------ >8 ------------------------\nP1-05: not part of the message\n",
            $semicolon,
            '',
        ];
        yield 'multi-character prefix needs all characters' => ["% note\n", ['core.commentChar' => '%%'], '% note'];
    }

    /**
     * Real commits in a throwaway repository with the hook installed, so git itself decides
     * what the hook sees.
     */
    public function testRealCommitWithVerbatimCleanupRejectsCommentFirstLine(): void
    {
        [$code, , $stderr, $repo] = $this->commitInFixture("# note\nP1-05: x\n", [], ['--cleanup=verbatim']);

        self::assertNotSame(0, $code, 'git commit must fail');
        self::assertStringContainsString('commit-msg: rejected subject: "# note"', $stderr);
        self::assertStringContainsString('starts with the comment character "#"', $stderr);
        self::assertFalse($this->fixtureHasCommit($repo), 'no commit may be created');
    }

    public function testRealCommitWithVerbatimCleanupAcceptsSubjectFirst(): void
    {
        [$code, , $stderr, $repo] = $this->commitInFixture("P1-05: x\n\n# note\n", [], ['--cleanup=verbatim']);

        self::assertSame(0, $code, $stderr);
        self::assertSame('P1-05: x', $this->fixtureGit($repo, ['log', '-1', '--format=%s']));
    }

    public function testRealCommitHonoursSemicolonCommentChar(): void
    {
        $config = ['-c', 'core.commentChar=;'];

        [$code, , $stderr, $repo] = $this->commitInFixture("P1-05: x\n\n; dropped by strip\n", $config, ['--cleanup=strip']);
        self::assertSame(0, $code, $stderr);
        self::assertSame('P1-05: x', $this->fixtureGit($repo, ['log', '-1', '--format=%B']), 'git stripped the ; line as a comment');

        [$code, , $stderr, $repo] = $this->commitInFixture("; note\nP1-05: x\n", $config, ['--cleanup=strip']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('starts with the comment character ";"', $stderr);
        self::assertFalse($this->fixtureHasCommit($repo));

        [$code, , $stderr, $repo] = $this->commitInFixture("# P1-05\n", $config, ['--cleanup=strip']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('commit-msg: rejected subject: "# P1-05"', $stderr);
        self::assertStringNotContainsString('comment character', $stderr);
        self::assertFalse($this->fixtureHasCommit($repo));
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
     * Runs commit-msg on $message. $config (git config key => value) reaches the hook's
     * `git config` call through GIT_CONFIG_COUNT/KEY/VALUE, as `git -c` would pass it.
     *
     * @param array<string, string> $config
     * @return array{int, string, string}
     */
    private function runCommitMsg(string $message, array $config = []): array
    {
        $env = [];
        foreach (array_keys($config) as $index => $key) {
            $env["GIT_CONFIG_KEY_{$index}"] = $key;
            $env["GIT_CONFIG_VALUE_{$index}"] = $config[$key];
        }
        if ($config !== []) {
            $env['GIT_CONFIG_COUNT'] = (string) count($config);
        }

        return $this->runHook('commit-msg', [$this->tempFile($message)], '', $env);
    }

    /**
     * Runs a hook from .githooks/ through sh. The working directory is a fresh temp dir and
     * GIT_CEILING_DIRECTORIES stops git from searching above it, so the hook's own git calls
     * never read this repository's config.
     *
     * @param list<string> $arguments
     * @param array<string, string|null> $env
     * @return array{int, string, string}
     */
    private function runHook(string $hook, array $arguments, string $stdin = '', array $env = []): array
    {
        $sh = $this->requireSh();
        $path = self::repoRoot() . '/.githooks/' . $hook;
        self::assertFileExists($path);
        $cwd = $this->tempDir();

        return self::runProcess(
            [$sh, self::toForwardSlashes($path), ...array_map(self::toForwardSlashes(...), $arguments)],
            $stdin,
            $cwd,
            $env + ['GIT_CEILING_DIRECTORIES' => dirname($cwd)],
        );
    }

    /**
     * Makes a new repository with .githooks/commit-msg installed and runs
     * `git <options> commit --allow-empty <arguments> -F <message file>` in it.
     *
     * @param list<string> $gitOptions
     * @param list<string> $commitArguments
     * @return array{int, string, string, string} exit code, stdout, stderr, repository path
     */
    private function commitInFixture(string $message, array $gitOptions, array $commitArguments): array
    {
        $this->requireGit();
        $this->requireSh();
        $repo = $this->tempDir();
        $this->fixtureGit($repo, ['init', '-q']);
        $hook = $repo . '/.git/hooks/commit-msg';
        self::assertTrue(copy(self::repoRoot() . '/.githooks/commit-msg', $hook));
        chmod($hook, 0755);

        $result = self::runProcess(
            ['git', ...$gitOptions, 'commit', '-q', '--allow-empty', ...$commitArguments, '-F', $this->tempFile($message)],
            '',
            $repo,
            self::authorEnvironment(),
        );
        $result[] = $repo;

        return $result;
    }

    private function fixtureHasCommit(string $repo): bool
    {
        [$code] = self::runProcess(['git', 'rev-parse', '-q', '--verify', 'HEAD'], '', $repo);

        return $code === 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function fixtureGit(string $repo, array $arguments): string
    {
        [$code, $stdout, $stderr] = self::runProcess(['git', ...$arguments], '', $repo, self::authorEnvironment());
        self::assertSame(0, $code, 'git ' . implode(' ', $arguments) . " failed: {$stderr}");

        return trim(str_replace("\r\n", "\n", $stdout));
    }

    /**
     * @return array<string, string>
     */
    private static function authorEnvironment(): array
    {
        return [
            'GIT_AUTHOR_NAME' => 'Guard Test',
            'GIT_AUTHOR_EMAIL' => 'guard@example.invalid',
            'GIT_COMMITTER_NAME' => 'Guard Test',
            'GIT_COMMITTER_EMAIL' => 'guard@example.invalid',
        ];
    }
}
