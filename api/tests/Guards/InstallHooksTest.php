<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

/**
 * Drives api/bin/install-hooks.php against a throwaway git repository and a linked worktree of
 * it, both created under the system temp dir. The real repository is never touched: every child
 * process runs without inherited GIT_* variables and without the developer's system or global
 * git config, and setUp checks that git resolves the fixture to itself before any test runs the
 * installer.
 */
final class InstallHooksTest extends GuardTestCase
{
    private const MARKER = '# fx-installments managed hook';
    private const HOOKS = ['commit-msg', 'pre-push'];
    private const FOREIGN = "#!/bin/sh\necho somebody else's hook\n";

    private string $repo;
    private string $worktree;
    private string $hooksDir;

    /** @var array<string, string|null> extra environment for the fixture's git commands */
    private array $gitEnv = [];

    protected function setUp(): void
    {
        $this->requireGit();
        $this->createFixture();
    }

    private function createFixture(): void
    {
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
            self::assertSame([], $this->backupFiles($hook));
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
            self::assertSame([], $this->backupFiles($hook));
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
        $backups = $this->backups('commit-msg');
        self::assertSame([$old], array_values($backups));
        self::assertMatchesRegularExpression('/^commit-msg\.bak\.\d{8}-\d{6}$/', array_key_first($backups));
        self::assertFileDoesNotExist($this->hooksDir . '/commit-msg.bak');
        self::assertSame([], $this->backupFiles('pre-push'));
        self::assertBackupPrinted($stdout, 'commit-msg', array_key_first($backups));
    }

    public function testMainCheckoutRefusesForeignHookAndWritesNothing(): void
    {
        file_put_contents($this->hooksDir . '/pre-push', self::FOREIGN);

        [$code, , $stderr] = $this->install($this->repo);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('refusing to overwrite', $stderr);
        self::assertStringContainsString('pre-push', $stderr);
        self::assertStringContainsString('FORCE=1', $stderr);
        self::assertSame(self::FOREIGN, file_get_contents($this->hooksDir . '/pre-push'));
        self::assertSame([], $this->backupFiles('pre-push'));
        self::assertFileDoesNotExist($this->hooksDir . '/commit-msg', 'a refusal must not install the other hooks either');
    }

    public function testMainCheckoutForceReplacesForeignHookKeepingBackup(): void
    {
        file_put_contents($this->hooksDir . '/pre-push', self::FOREIGN);

        [$code, $stdout, $stderr] = $this->install($this->repo, ['--force']);

        self::assertSame(0, $code, $stderr);
        $this->assertInstalled('pre-push');
        $this->assertInstalled('commit-msg');
        self::assertSame([self::FOREIGN], array_values($this->backups('pre-push')));
        self::assertStringContainsString('kept the previous pre-push as ', $stdout);
    }

    /**
     * The P0-06 acceptance scenario: `make init FORCE=1` over a foreign hook, then an update of
     * the managed hook. Before P0-06 the second run replaced pre-push.bak (the foreign hook) with
     * the old managed version.
     */
    public function testForceOverForeignHookThenManagedUpdateKeepsForeignBackup(): void
    {
        file_put_contents($this->hooksDir . '/pre-push', self::FOREIGN);
        [$code, , $stderr] = $this->install($this->repo, ['--force']);
        self::assertSame(0, $code, $stderr);
        $firstManaged = (string) file_get_contents($this->hooksDir . '/pre-push');

        // A new version of the managed hook arrives on the branch (still carries the marker).
        file_put_contents($this->repo . '/.githooks/pre-push', "# managed update\n", FILE_APPEND);
        [$code, $stdout, $stderr] = $this->install($this->repo);

        self::assertSame(0, $code, $stderr);
        $this->assertInstalled('pre-push');
        $backups = $this->backups('pre-push');
        self::assertCount(2, $backups, 'one backup per replaced hook: ' . implode(', ', array_keys($backups)));
        self::assertContains(self::FOREIGN, $backups, 'the foreign hook must still exist in a backup file');
        self::assertContains($firstManaged, $backups, 'the replaced managed hook is backed up too');
        self::assertStringContainsString('kept the previous pre-push as ', $stdout);
    }

    public function testExistingBackupFilesAreNeverOverwritten(): void
    {
        // A plain .bak from before P0-06, and every timestamped name the run could pick in the
        // next 30 seconds, so the installer has to fall back to a numeric suffix.
        $existing = ['pre-push.bak' => self::FOREIGN];
        $now = time();
        for ($second = 0; $second <= 30; $second++) {
            $existing['pre-push.bak.' . gmdate('Ymd-His', $now + $second)] = "taken {$second}\n";
        }
        foreach ($existing as $name => $contents) {
            file_put_contents($this->hooksDir . '/' . $name, $contents);
        }
        $old = "#!/bin/sh\n" . self::MARKER . "\necho old managed version\n";
        file_put_contents($this->hooksDir . '/pre-push', $old);

        [$code, $stdout, $stderr] = $this->install($this->repo);

        self::assertSame(0, $code, $stderr);
        $this->assertInstalled('pre-push');
        foreach ($existing as $name => $contents) {
            self::assertSame($contents, file_get_contents($this->hooksDir . '/' . $name), "{$name} must be untouched");
        }
        $new = array_diff_key($this->backups('pre-push'), $existing);
        self::assertSame([$old], array_values($new), 'exactly one new backup holding the replaced hook');
        self::assertMatchesRegularExpression('/^pre-push\.bak\.\d{8}-\d{6}-1$/', array_key_first($new));
        self::assertBackupPrinted($stdout, 'pre-push', array_key_first($new));
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
     * A developer's global config with init.templateDir pointing at a template that holds a
     * foreign pre-push would break the fixture: `git init` would copy that hook in, setUp would
     * find it, and the installer would refuse. The isolation in GuardTestCase::runProcess must
     * make such a config invisible.
     */
    public function testFixtureIgnoresDevelopersGlobalGitConfig(): void
    {
        $home = $this->tempDir();
        mkdir($home . '/template/hooks', 0777, true);
        file_put_contents($home . '/template/hooks/pre-push', self::FOREIGN);
        file_put_contents($home . '/.gitconfig', "[init]\n\ttemplateDir = {$home}/template\n");
        $developer = ['HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/xdg'];

        // Without the isolation the file is read and does break a fresh repository.
        $plain = $this->tempDir();
        $unisolated = $developer + ['GIT_CONFIG_GLOBAL' => null, 'GIT_CONFIG_NOSYSTEM' => null];
        [$code, $stdout, $stderr] = self::runProcess(['git', 'config', '--global', '--get', 'init.templateDir'], '', $plain, $unisolated);
        self::assertSame(0, $code, $stderr);
        self::assertSame("{$home}/template", trim($stdout));
        [$code, , $stderr] = self::runProcess(['git', 'init', '-q', 'repo'], '', $plain, $unisolated);
        self::assertSame(0, $code, $stderr);
        self::assertFileExists($plain . '/repo/.git/hooks/pre-push', 'the bogus config must really apply without isolation');

        // With the isolation the same HOME has no effect: the fixture builds and installs cleanly.
        [$code, $stdout] = self::runProcess(['git', 'config', '--global', '--get', 'init.templateDir'], '', $plain, $developer);
        self::assertSame(1, $code, 'the isolated child sees no global init.templateDir');
        self::assertSame('', trim($stdout));

        $this->gitEnv = $developer;
        $this->createFixture();
        [$code, , $stderr] = self::runProcess(
            [PHP_BINARY, $this->repo . '/api/bin/install-hooks.php'],
            '',
            $this->repo,
            $developer,
        );
        self::assertSame(0, $code, $stderr);
        foreach (self::HOOKS as $hook) {
            $this->assertInstalled($hook);
            self::assertSame([], $this->backupFiles($hook));
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function install(string $checkout, array $arguments = []): array
    {
        return self::runProcess([PHP_BINARY, $checkout . '/api/bin/install-hooks.php', ...$arguments], '', $checkout);
    }

    /**
     * The installer prints the full backup path. The directory part is compared loosely, since
     * git and realpath() may spell the temp dir differently on Windows (case, short names).
     */
    private static function assertBackupPrinted(string $stdout, string $hook, ?string $backupName): void
    {
        self::assertNotNull($backupName);
        self::assertMatchesRegularExpression(
            '~^install-hooks: kept the previous ' . preg_quote($hook, '~') . ' as .+/hooks/'
                . preg_quote($backupName, '~') . '\r?$~m',
            $stdout,
            'the backup path is printed',
        );
    }

    private function assertInstalled(string $hook): void
    {
        $installed = $this->hooksDir . '/' . $hook;
        self::assertFileExists($installed);
        self::assertSame(file_get_contents($this->repo . '/.githooks/' . $hook), file_get_contents($installed));
    }

    /**
     * Every backup-like file for $hook in the hooks dir: <hook>.bak and <hook>.bak.*.
     *
     * @return list<string> file names
     */
    private function backupFiles(string $hook): array
    {
        return array_values(array_filter(
            scandir($this->hooksDir) ?: [],
            static fn (string $name): bool => $name === "{$hook}.bak" || str_starts_with($name, "{$hook}.bak."),
        ));
    }

    /**
     * Timestamped backups of $hook (<hook>.bak.<YYYYMMDD-HHMMSS>[-<n>]), sorted by name.
     *
     * @return array<string, string> file name => contents
     */
    private function backups(string $hook): array
    {
        $backups = [];
        foreach ($this->backupFiles($hook) as $name) {
            if (preg_match('/^' . preg_quote($hook, '/') . '\.bak\.\d{8}-\d{6}(?:-\d+)?$/', $name) === 1) {
                $backups[$name] = (string) file_get_contents($this->hooksDir . '/' . $name);
            }
        }
        ksort($backups);

        return $backups;
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
        ] + $this->gitEnv);
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
