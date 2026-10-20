<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Guards;

use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the guard tests: locating sh and git, running processes with a clean
 * environment, and temporary files and directories that are removed after each test.
 *
 * When a tool is missing the test is skipped with a message, except when the CI environment
 * variable is set: then it fails, so CI can never pass by skipping the guards.
 */
abstract class GuardTestCase extends TestCase
{
    private static ?string $sh = null;
    private static bool $shSearched = false;
    private static ?bool $gitAvailable = null;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            self::removeTree($path);
        }
        $this->tempPaths = [];
    }

    protected static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The sh to run hooks with; skips the test (fails it on CI) when none is found.
     */
    protected function requireSh(): string
    {
        $sh = self::locateSh();
        if ($sh === null) {
            $override = getenv('SH_PATH');
            $this->missingTool(
                is_string($override) && $override !== ''
                    ? "No POSIX sh found: SH_PATH={$override} is not an existing file"
                    : 'No POSIX sh found (looked on Windows next to the git install, on PATH and in the '
                        . 'Git for Windows default folders; elsewhere at /bin/sh and on PATH)',
            );
        }

        return $sh;
    }

    /**
     * Skips the test (fails it on CI) when git cannot be run.
     */
    protected function requireGit(): void
    {
        if (self::$gitAvailable === null) {
            [$code] = self::runProcess(['git', '--version']);
            self::$gitAvailable = $code === 0;
        }
        if (!self::$gitAvailable) {
            $this->missingTool('git could not be run');
        }
    }

    private function missingTool(string $reason): never
    {
        $ci = getenv('CI');
        if (is_string($ci) && $ci !== '' && $ci !== 'false' && $ci !== '0') {
            self::fail("{$reason}; CI is set, so the guard tests fail instead of skipping.");
        }
        self::markTestSkipped("{$reason}; guard test not run.");
    }

    /**
     * Runs a command without a shell. The child gets this process's environment minus every GIT_*
     * variable (git sets some of them while running hooks, and they would point the child at the
     * real repository), plus $env overrides. A null override removes the variable.
     *
     * @param list<string> $command
     * @param array<string, string|null> $env
     * @return array{int, string, string} exit code, stdout, stderr
     */
    protected static function runProcess(array $command, string $stdin = '', ?string $cwd = null, array $env = []): array
    {
        $errFile = tempnam(sys_get_temp_dir(), 'fxerr');
        self::assertIsString($errFile);

        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            $cwd,
            self::childEnvironment($env),
        );
        if (!is_resource($process)) {
            unlink($errFile);

            return [127, '', 'could not start ' . $command[0]];
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $stderr = (string) file_get_contents($errFile);
        unlink($errFile);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, string>
     */
    private static function childEnvironment(array $overrides): array
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            if (stripos((string) $name, 'GIT_') !== 0) {
                $env[(string) $name] = (string) $value;
            }
        }
        foreach ($overrides as $name => $value) {
            // Environment names are case-insensitive on Windows (PATH is usually "Path").
            foreach (array_keys($env) as $existing) {
                if (PHP_OS_FAMILY === 'Windows' ? strcasecmp($existing, $name) === 0 : $existing === $name) {
                    unset($env[$existing]);
                }
            }
            if ($value !== null) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * The current PATH with $directory in front.
     */
    protected static function pathWith(string $directory): string
    {
        $path = getenv('PATH');

        return $directory . PATH_SEPARATOR . (is_string($path) ? $path : '');
    }

    protected function tempFile(string $contents = ''): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fxhook');
        self::assertIsString($file);
        $this->tempPaths[] = $file;
        file_put_contents($file, $contents);

        return $file;
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/fxguard-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0777, true), "could not create {$dir}");
        $this->tempPaths[] = $dir;
        $real = realpath($dir);
        self::assertIsString($real);

        return str_replace('\\', '/', $real);
    }

    protected static function toForwardSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    /**
     * Lookup order: SH_PATH when set (and nothing else, so a wrong value is noticed); on Windows,
     * bin/sh.exe and usr/bin/sh.exe in the Git for Windows install found through
     * `git --exec-path`, then sh.exe on PATH, then the default install folders; elsewhere,
     * /bin/sh, then sh on PATH.
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
        } elseif ($windows) {
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
        [$code, $stdout] = self::runProcess(['git', '--exec-path']);
        $execPath = trim($stdout);
        if ($code !== 0 || $execPath === '') {
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
            array_map(
                static fn (string $dir): string => rtrim(trim($dir, " \""), '\\/'),
                explode(PATH_SEPARATOR, $path),
            ),
            static fn (string $dir): bool => $dir !== '',
        ));
    }
}
