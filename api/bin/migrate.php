<?php

declare(strict_types=1);

/*
 * Database schema and retention (docs/PLAN.md §5).
 *
 *     php api/bin/migrate.php          apply every pending migration to DB_DSN
 *                                      (default api/var/app.db)
 *     php api/bin/migrate.php --prune  delete expired rows (refuses while the schema is behind)
 *     php api/bin/migrate.php --dump   regenerate api/db/schema.sql from the migration list
 *
 * Run it at install and on every deploy: the app never migrates, and answers 500 INTERNAL while
 * the database is behind the code. Run --prune from a daily job. Plain migrate is the only
 * command that creates the database: a missing SQLite file, and its folder, whatever DB_DSN's
 * path. `--prune` refuses a missing file without creating it, like every other CLI (L-04).
 * Failures print `migrate:`, or `prune:` for `--prune`, on stderr. Exit codes: 0 done,
 * 1 failed, 2 usage.
 */

use FxInstallments\Bootstrap;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Database;
use FxInstallments\Db\DatabaseFileMissing;
use FxInstallments\Db\Migrator;
use FxInstallments\Db\Retention;
use FxInstallments\Db\Schema;
use FxInstallments\Db\SchemaOutOfDate;

$apiRoot = dirname(__DIR__);
require $apiRoot . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$mode = match ($args) {
    [] => 'migrate',
    ['--prune'] => 'prune',
    ['--dump'] => 'dump',
    default => null,
};
if ($mode === null) {
    fwrite(STDERR, "usage: php api/bin/migrate.php [--prune | --dump]\n");
    exit(2);
}
// What a failure line starts with: `prune:` for --prune, `migrate:` otherwise.
$prefix = $mode === 'prune' ? 'prune' : 'migrate';

try {
    if ($mode === 'dump') {
        // An in-memory database only: no environment, no .env.
        $path = Schema::write();
        fwrite(STDOUT, "migrate: wrote {$path}\n");
        exit(0);
    }

    Bootstrap::init($apiRoot);

    if ($mode === 'migrate') {
        $migrator = new Migrator(Database::fromEnvironment($apiRoot, create: true));
        $from = $migrator->currentVersion();
        $applied = $migrator->migrate();
        foreach ($applied as $migration) {
            fwrite(STDOUT, sprintf("migrate: applied %d %s\n", $migration->version, $migration->name));
        }
        fwrite(STDOUT, $applied === []
            ? sprintf("migrate: schema is current (version %d)\n", $from)
            : sprintf("migrate: schema version %d -> %d\n", $from, $migrator->currentVersion()));
        exit(0);
    }

    // Refuses a missing file, and a schema behind the code or corrupt, before writing anything.
    $pdo = Database::fromEnvironment($apiRoot);
    $config = new ConfigRepository();
    $retention = new Retention($pdo, $config->int('requests.retention_days'), $config->int('block.window_minutes'));
    foreach ($retention->prune(time()) as $table => $count) {
        fwrite(STDOUT, sprintf("prune: %s %d deleted\n", $table, $count));
    }
    exit(0);
} catch (SchemaOutOfDate $e) {
    fwrite(STDERR, 'prune: ' . $e->getMessage() . "\n");
    exit(1);
} catch (DatabaseFileMissing $e) {
    fwrite(STDERR, "{$prefix}: failed: " . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("%s: failed: %s: %s\n", $prefix, $e::class, $e->getMessage()));
    exit(1);
}
