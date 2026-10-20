<?php

declare(strict_types=1);

/*
 * Installs or verifies the git hooks in .githooks/.
 *
 * - Main checkout (`git rev-parse --git-dir` equals `--git-common-dir`): copies .githooks/* into
 *   `git rev-parse --git-path hooks` and makes them executable. A hook that is already identical
 *   is left alone. A differing hook that carries the marker line below is renamed to <name>.bak
 *   first. A differing hook without the marker is not ours: the script refuses, writes nothing and
 *   exits 1, unless --force is given (then that hook is also kept as <name>.bak).
 * - Linked worktree (the two differ): the hooks dir is shared with the main checkout, so nothing
 *   is written. The installed hooks are only compared byte for byte with this branch's
 *   .githooks/*; a missing or differing hook prints a warning. Exits 0 either way.
 *
 * Written in PHP so `make init` works from cmd.exe and sh alike.
 *
 * Usage: php api/bin/install-hooks.php [--force]
 */

const MANAGED_MARKER = '# fx-installments managed hook';

$arguments = array_slice($argv, 1);
$force = false;
foreach ($arguments as $argument) {
    if ($argument === '--force') {
        $force = true;
        continue;
    }
    fwrite(STDERR, "install-hooks: unknown argument {$argument}. Usage: php api/bin/install-hooks.php [--force]" . PHP_EOL);
    exit(2);
}

$repoRoot = dirname(__DIR__, 2);
$sourceDir = $repoRoot . '/.githooks';

/**
 * @return array<string, string> hook name => contents
 */
function readSourceHooks(string $sourceDir): array
{
    if (!is_dir($sourceDir)) {
        fail("{$sourceDir} not found");
    }
    $hooks = [];
    foreach (scandir($sourceDir) ?: [] as $name) {
        $path = $sourceDir . '/' . $name;
        if ($name === '.' || $name === '..' || !is_file($path)) {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            fail("could not read {$path}");
        }
        if (!str_contains($contents, MANAGED_MARKER)) {
            fail("{$path} lacks the marker line '" . MANAGED_MARKER . "'");
        }
        $hooks[$name] = $contents;
    }
    if ($hooks === []) {
        fail("no hooks found in {$sourceDir}");
    }

    return $hooks;
}

/**
 * @param list<string> $arguments
 * @return list<string> output lines
 */
function git(array $arguments, string $cwd): array
{
    $process = proc_open(
        ['git', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );
    if (!is_resource($process)) {
        fail('could not run git');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fail('`git ' . implode(' ', $arguments) . "` failed: {$stderr}");
    }

    return preg_split('/\r?\n/', trim($stdout)) ?: [];
}

function canonical(string $path): string
{
    $resolved = realpath($path);
    $normal = rtrim(str_replace('\\', '/', $resolved === false ? $path : $resolved), '/');

    return PHP_OS_FAMILY === 'Windows' ? strtolower($normal) : $normal;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "install-hooks: {$message}" . PHP_EOL);
    exit($code);
}

$sources = readSourceHooks($sourceDir);

$lines = git(['rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir', '--git-path', 'hooks'], $repoRoot);
if (count($lines) !== 3) {
    fail('unexpected output from git rev-parse: ' . implode(' | ', $lines));
}
[$gitDir, $commonDir, $hooksDir] = $lines;
$hooksDir = rtrim(str_replace('\\', '/', $hooksDir), '/');

if (canonical($gitDir) !== canonical($commonDir)) {
    // Linked worktree: verify only.
    $stale = [];
    foreach ($sources as $name => $contents) {
        $target = $hooksDir . '/' . $name;
        if (!is_file($target)) {
            $stale[] = "{$name} (missing)";
        } elseif (file_get_contents($target) !== $contents) {
            $stale[] = "{$name} (differs)";
        }
    }
    if ($stale !== []) {
        fwrite(STDERR, 'install-hooks: warning: hooks differ from this branch; run make init from the main checkout' . PHP_EOL);
        fwrite(STDERR, "  This is a linked worktree, so nothing was written. Shared hooks dir: {$hooksDir}" . PHP_EOL);
        foreach ($stale as $entry) {
            fwrite(STDERR, "  - {$entry}" . PHP_EOL);
        }
        exit(0);
    }
    echo "install-hooks: linked worktree; the hooks in {$hooksDir} match this branch, nothing written" . PHP_EOL;
    exit(0);
}

// Main checkout: plan every hook first, so a refusal writes nothing.
$plan = [];
$foreign = [];
foreach ($sources as $name => $contents) {
    $target = $hooksDir . '/' . $name;
    if (!file_exists($target)) {
        $plan[$name] = 'install';
        continue;
    }
    $existing = (string) file_get_contents($target);
    if ($existing === $contents) {
        $plan[$name] = 'same';
    } elseif (str_contains($existing, MANAGED_MARKER)) {
        $plan[$name] = 'backup';
    } elseif ($force) {
        $plan[$name] = 'backup';
    } else {
        $foreign[] = $name;
    }
}

if ($foreign !== []) {
    fail(
        'refusing to overwrite hook(s) not managed by this project: ' . implode(', ', $foreign)
        . " in {$hooksDir} (no '" . MANAGED_MARKER . "' line). Nothing was written."
        . PHP_EOL . '  Move them away, or run `make init FORCE=1` (php api/bin/install-hooks.php --force)'
        . ' to replace them; the old file is kept as <name>.bak.',
    );
}

if (!is_dir($hooksDir) && !mkdir($hooksDir, 0777, true) && !is_dir($hooksDir)) {
    fail("could not create {$hooksDir}");
}

foreach ($plan as $name => $action) {
    $target = $hooksDir . '/' . $name;
    if ($action === 'same') {
        echo "install-hooks: {$name} is up to date" . PHP_EOL;
        continue;
    }
    if ($action === 'backup') {
        $backup = $target . '.bak';
        if (file_exists($backup) && !unlink($backup)) {
            fail("could not remove old {$backup}");
        }
        if (!rename($target, $backup)) {
            fail("could not rename {$target} to {$backup}");
        }
        echo "install-hooks: kept the previous {$name} as {$backup}" . PHP_EOL;
    }
    if (file_put_contents($target, $sources[$name]) !== strlen($sources[$name])) {
        fail("could not write {$target}");
    }
    // No-op on Windows, where Git for Windows runs hooks through its own sh.
    chmod($target, 0755);
    echo "install-hooks: {$name} -> {$target}" . PHP_EOL;
}

echo 'install-hooks: ' . count($plan) . ' hook(s) checked in ' . $hooksDir . PHP_EOL;
