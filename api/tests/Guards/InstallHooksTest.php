<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

/**
 * Drives api/bin/install-hooks.php against a throwaway git repository and a linked worktree of
 * it, both created under the system temp dir. The real repository is never touched: every child
 * process runs without inherited GIT_* variables, and setUp checks that git resolves the
 * fixture to itself before any test runs the installer.
 */
final class InstallHooksTest extends GuardTestCase
{
    private const MARKER = '# fx-installments managed hook';
    private const HOOKS = ['commit-msg', 'pre-push'];

    private string $repo;
    private string $worktree;
    private string $hooksDir;

    protected function setUp(): void
    {
        $this->requireGit();

        $base = $this->tempDir();
        $this->repo = $base . '/repo';
        $this->worktree = $base . '/wt';
        $this->hooksDir = $this->repo . '/.git/hooks';

        mkdir($this->repo . '/.githooks', 0777, true);
        mkdir($this->repo . '/api/bin', 0777, true);
        foreach (self::HOOKS as $hook) {
            copy(self::repoRoot() . '/.githooks/' . $hook, $this->repo . '/.githooks/' . $hook);
        }
        copy(self::repoRoot() . '/api/bin/install-hooks.php', $this->repo . '/api/bin/install-hooks.php');
        // No line-ending conversion, so the worktree's copies are byte-identical to the repo's.
        file_put_contents($this->repo . '/.gitattributes', "* -text\n");

        $this->git($base, ['init', '-q', 'repo']);
        self::assertSame(
            self::canonical($this->repo),
            self::canonical($this->git($this->repo, ['rev-parse', '--show-toplevel'])),
            'the fixture repository must resolve to itself',
        );

        // Plumbing only: no hooks, no signing prompts, no user config needed.
        $this->git($this->repo, ['add', '-A']);
        $tree = $this->git($this->repo, ['write-tree']);
        $commit = $this->git($this->repo, ['commit-tree', '-m', 'P0-04: fixture', $tree]);
        $this->git($this->repo, ['update-ref', 'HEAD', $commit]);
        $this->git($this->repo, ['worktree', 'add', '-q', '--detach', $this->worktree, 'HEAD']);

        self::assertSame(
            self::canonical($this->worktree),
            self::canonical($this->git($this->worktree, ['rev-parse', '--show-toplevel'])),
        );
        self::assertSame(
            self::canonical($this->hooksDir),
            self::canonical($this->git($this->worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'hooks'])),
            'the worktree must share the fixture repository hooks dir',
        );
        foreach (self::HOOKS as $hook) {
            self::assertFileDoesNotExist($this->hooksDir . '/' . $hook);
        }
    }

    public function testMainCheckoutInstallsMissingHooks(): void
    {
        [$code, $stdout, $stderr] = $this->install($this->repo);

        self::assertSame(0, $code, $stderr);
        foreach (self::HOOKS as $hook) {
            $this->assertInstalled($hook);
            self::assertFileDoesNotExist($this->hooksDir . "/{$hook}.bak");
            self::assertStringContainsString("{$hook} -> ", $stdout);
        }
    }

    public function testMainCheckoutLeavesIdenticalHooksAlone(): void
    {
        $this->install($this->repo);
        [$code, $stdout, $stderr] = $this->install($this->repo);

        self::assertSame(0, $code, $stderr);
        foreach (self::HOOKS as $hook) {
            self::assertStringContainsString("{$hook} is up to date", $stdout);
            self::assertFileDoesNotExist($this->hooksDir . "/{$hook}.bak");
        }
    }

    public function testMainCheckoutBacksUpDifferingManagedHook(): void
    {
        $old = "#!/bin/sh\n" . self::MARKER . "\necho old managed version\n";
        file_put_contents($this->hooksDir . '/commit-msg', $old);

        [$code, $stdout, $stderr] = $this->install($this->repo);

        self::assertSame(0, $code, $stderr);
        $this->assertInstalled('commit-msg');
        $this->assertInstalled('pre-push');
        self::assertSame($old, file_get_contents($this->hooksDir . '/commit-msg.bak'));
        self::assertFileDoesNotExist($this->hooksDir . '/pre-push.bak');
        self::assertStringContainsString('kept the previous commit-msg', $stdout);
    }

    public function testMainCheckoutRefusesForeignHookAndWritesNothing(): void
    {
        $foreign = "#!/bin/sh\necho somebody else's hook\n";
        file_put_contents($this->hooksDir . '/pre-push', $foreign);

        [$code, , $stderr] = $this->install($this->repo);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('refusing to overwrite', $stderr);
        self::assertStringContainsString('pre-push', $stderr);
        self::assertStringContainsString('FORCE=1', $stderr);
        self::assertSame($foreign, file_get_contents($this->hooksDir . '/pre-push'));
        self::assertFileDoesNotExist($this->hooksDir . '/pre-push.bak');
        self::assertFileDoesNotExist($this->hooksDir . '/commit-msg', 'a refusal must not install the other hooks either');
    }

    public function testMainCheckoutForceReplacesForeignHookKeepingBackup(): void
    {
        $foreign = "#!/bin/sh\necho somebody else's hook\n";
        file_put_contents($this->hooksDir . '/pre-push', $foreign);

        [$code, , $stderr] = $this->install($this->repo, ['--force']);

        self::assertSame(0, $code, $stderr);
        $this->assertInstalled('pre-push');
        $this->assertInstalled('commit-msg');
        self::assertSame($foreign, file_get_contents($this->hooksDir . '/pre-push.bak'));
    }

    public function testUnknownArgumentIsRejected(): void
    {
        [$code, , $stderr] = $this->install($this->repo, ['--frce']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('unknown argument', $stderr);
        self::assertFileDoesNotExist($this->hooksDir . '/commit-msg');
    }

    public function testLinkedWorktreeWarnsAndWritesNothingWhenHooksAreMissing(): void
    {
        $before = $this->snapshotHooksDir();

        [$code, , $stderr] = $this->install($this->worktree);

        self::assertSame(0, $code, 'make init must still work in a worktree');
        self::assertStringContainsString('hooks differ from this branch; run make init from the main checkout', $stderr);
        self::assertStringContainsString('commit-msg (missing)', $stderr);
        self::assertStringContainsString('pre-push (missing)', $stderr);
        self::assertSame($before, $this->snapshotHooksDir(), 'a worktree run must not write to the hooks dir');
    }

    public function testLinkedWorktreeWarnsAndWritesNothingWhenHooksDiffer(): void
    {
        $this->install($this->repo);
        file_put_contents($this->hooksDir . '/commit-msg', "\n# local edit\n", FILE_APPEND);
        $before = $this->snapshotHooksDir();

        [$code, , $stderr] = $this->install($this->worktree, ['--force']);

        self::assertSame(0, $code);
        self::assertStringContainsString('hooks differ from this branch; run make init from the main checkout', $stderr);
        self::assertStringContainsString('commit-msg (differs)', $stderr);
        self::assertStringNotContainsString('pre-push', $stderr);
        self::assertSame($before, $this->snapshotHooksDir(), 'not even --force writes from a worktree');
    }

    public function testLinkedWorktreeReportsMatchingHooks(): void
    {
        $this->install($this->repo);
        $before = $this->snapshotHooksDir();

        [$code, $stdout, $stderr] = $this->install($this->worktree);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);
        self::assertStringContainsString('linked worktree', $stdout);
        self::assertStringContainsString('match this branch', $stdout);
        self::assertSame($before, $this->snapshotHooksDir());
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function install(string $checkout, array $arguments = []): array
    {
        return self::runProcess([PHP_BINARY, $checkout . '/api/bin/install-hooks.php', ...$arguments], '', $checkout);
    }

    private function assertInstalled(string $hook): void
    {
        $installed = $this->hooksDir . '/' . $hook;
        self::assertFileExists($installed);
        self::assertSame(file_get_contents($this->repo . '/.githooks/' . $hook), file_get_contents($installed));
    }

    /**
     * @return array<string, string> file name => sha1 of its contents
     */
    private function snapshotHooksDir(): array
    {
        $snapshot = [];
        foreach (scandir($this->hooksDir) ?: [] as $name) {
            $path = $this->hooksDir . '/' . $name;
            if (is_file($path)) {
                $snapshot[$name] = (string) sha1_file($path);
            }
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param list<string> $arguments
     */
    private function git(string $cwd, array $arguments): string
    {
        [$code, $stdout, $stderr] = self::runProcess(['git', ...$arguments], '', $cwd, [
            'GIT_AUTHOR_NAME' => 'Guard Test',
            'GIT_AUTHOR_EMAIL' => 'guard@example.invalid',
            'GIT_COMMITTER_NAME' => 'Guard Test',
            'GIT_COMMITTER_EMAIL' => 'guard@example.invalid',
        ]);
        self::assertSame(0, $code, 'git ' . implode(' ', $arguments) . " failed: {$stderr}");

        return trim($stdout);
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);
        $normal = rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normal) : $normal;
    }
}
