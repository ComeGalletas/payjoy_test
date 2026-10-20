<?php

declare(strict_types=1);

/*
 * Runs `php -l` on every .php file under src/, tests/, bin/ and public/ (those that exist),
 * relative to the api/ directory. Exits 1 if any file has a syntax error or no file is found.
 *
 * Usage: php bin/lint.php
 */

$apiRoot = dirname(__DIR__);
$directories = ['src', 'tests', 'bin', 'public'];

$files = [];
foreach ($directories as $directory) {
    $path = $apiRoot . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

if ($files === []) {
    fwrite(STDERR, "lint: no .php files found under " . implode(', ', $directories) . PHP_EOL);
    exit(1);
}

$failed = [];
foreach ($files as $file) {
    // An argument array bypasses the shell, so paths with spaces work on cmd and sh alike.
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "lint: could not start " . PHP_BINARY . PHP_EOL);
        exit(1);
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $failed[] = $file;
        fwrite(STDERR, trim($output) . PHP_EOL);
    }
}

printf("lint: %d file(s) checked, %d with errors\n", count($files), count($failed));
exit($failed === [] ? 0 : 1);
