<?php

declare(strict_types=1);

/*
 * Copies every file in .githooks/ into the hooks directory git reports for this checkout
 * (`git rev-parse --git-path hooks`, which also resolves correctly inside a worktree) and makes
 * the copies executable. Written in PHP so `make init` works from cmd.exe and sh alike.
 *
 * Usage: php api/bin/install-hooks.php
 */

$repoRoot = dirname(__DIR__, 2);
$sourceDir = $repoRoot . DIRECTORY_SEPARATOR . '.githooks';

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "install-hooks: {$sourceDir} not found" . PHP_EOL);
    exit(1);
}

$process = proc_open(
    ['git', 'rev-parse', '--git-path', 'hooks'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $repoRoot,
);
if (!is_resource($process)) {
    fwrite(STDERR, "install-hooks: could not run git" . PHP_EOL);
    exit(1);
}
$stdout = trim((string) stream_get_contents($pipes[1]));
$stderr = trim((string) stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $stdout === '') {
    fwrite(STDERR, "install-hooks: `git rev-parse --git-path hooks` failed: {$stderr}" . PHP_EOL);
    exit(1);
}

// git prints the path relative to the working directory unless it lives elsewhere.
$isAbsolute = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $stdout) === 1;
$hooksDir = $isAbsolute ? $stdout : $repoRoot . DIRECTORY_SEPARATOR . $stdout;

if (!is_dir($hooksDir) && !mkdir($hooksDir, 0777, true) && !is_dir($hooksDir)) {
    fwrite(STDERR, "install-hooks: could not create {$hooksDir}" . PHP_EOL);
    exit(1);
}

$installed = 0;
foreach (scandir($sourceDir) ?: [] as $name) {
    $source = $sourceDir . DIRECTORY_SEPARATOR . $name;
    if ($name === '.' || $name === '..' || !is_file($source)) {
        continue;
    }
    $target = rtrim(str_replace('\\', '/', $hooksDir), '/') . '/' . $name;
    if (!copy($source, $target)) {
        fwrite(STDERR, "install-hooks: could not copy {$name} to {$target}" . PHP_EOL);
        exit(1);
    }
    // No-op on Windows, where Git for Windows runs hooks through its own sh.
    chmod($target, 0755);
    echo "install-hooks: {$name} -> {$target}" . PHP_EOL;
    $installed++;
}

if ($installed === 0) {
    fwrite(STDERR, "install-hooks: no hooks found in {$sourceDir}" . PHP_EOL);
    exit(1);
}

echo "install-hooks: {$installed} hook(s) installed" . PHP_EOL;
