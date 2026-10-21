<?php

declare(strict_types=1);

/*
 * Manual IP blocks, for operators (ADR 0016).
 *
 *     php bin/ip.php block <ip> [--minutes=N] [--reason=TEXT]
 *                           block an IP, for N minutes or until unblocked; replaces any
 *                           block the IP has, automatic or manual
 *     php bin/ip.php unblock <ip>
 *                           end the IP's active block now; its earlier requests no longer
 *                           count toward an automatic block
 *     php bin/ip.php list   print every active block; manual blocks without an expiry keep
 *                           a raw IP until removed, so review them
 *
 * The IP is stored in its canonical form (lowercase, compressed IPv6), which is how PHP and
 * web servers report REMOTE_ADDR. Refuses to run while the database is behind the code (run
 * bin/migrate.php first). Exit codes: 0 done, 1 failed, 2 usage.
 */

use FxInstallments\Bootstrap;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migrator;
use FxInstallments\Http\IpBlock;
use FxInstallments\Http\IpBlocking;
use FxInstallments\Http\IpBlocks;

$apiRoot = dirname(__DIR__);
require $apiRoot . '/vendor/autoload.php';

const IP_USAGE = "usage: php bin/ip.php block <ip> [--minutes=N] [--reason=TEXT] | unblock <ip> | list\n";

$usage = static function (string $problem = ''): never {
    fwrite(STDERR, ($problem === '' ? '' : "ip: {$problem}\n") . IP_USAGE);
    exit(2);
};

$canonicalIp = static function (string $ip) use ($usage): string {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $usage('not a valid IP address');
    }
    $text = inet_ntop((string) inet_pton($ip));

    return $text === false ? $ip : $text;
};

$args = array_slice($argv, 1);
$command = $args[0] ?? null;
$ip = null;
$minutes = null;
$reason = 'manual';

if ($command === 'list' && count($args) === 1) {
    // nothing else to read
} elseif ($command === 'unblock' && count($args) === 2) {
    $ip = $canonicalIp($args[1]);
} elseif ($command === 'block' && count($args) >= 2 && count($args) <= 4) {
    $ip = $canonicalIp($args[1]);
    $seen = [];
    foreach (array_slice($args, 2) as $option) {
        if (preg_match('/^--(minutes|reason)=(.*)$/s', $option, $match) !== 1 || isset($seen[$match[1]])) {
            $usage();
        }
        $seen[$match[1]] = true;
        if ($match[1] === 'minutes') {
            if (preg_match('/^[1-9]\d{0,5}$/', $match[2]) !== 1 || (int) $match[2] > IpBlocking::MAX_MINUTES) {
                $usage('--minutes must be a whole number from 1 to ' . IpBlocking::MAX_MINUTES);
            }
            $minutes = (int) $match[2];
        } else {
            $reason = trim($match[2]);
            if ($reason === '' || strlen($reason) > IpBlocks::MAX_REASON || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
                $usage('--reason must be 1 to ' . IpBlocks::MAX_REASON . ' characters with no control characters');
            }
        }
    }
} else {
    $usage();
}

$time = static fn (?int $unix): string => $unix === null ? 'never' : gmdate('Y-m-d\TH:i:s\Z', $unix);
$describe = static fn (IpBlock $block): string => sprintf(
    '%s  %s  since %s  until %s  reason: %s',
    $block->ip,
    $block->source,
    $time($block->blockedAt),
    $time($block->expiresAt),
    $block->reason,
);

try {
    Bootstrap::init($apiRoot);
    $pdo = Database::fromEnvironment($apiRoot);
    (new Migrator($pdo))->assertCurrent();
    $blocks = new IpBlocks(Connection::wrap($pdo));
    $now = time();

    if ($command === 'block') {
        $block = $blocks->blockManually((string) $ip, $reason, $now, $minutes === null ? null : $now + $minutes * 60);
        fwrite(STDOUT, sprintf(
            "ip: blocked %s (manual) %s\n",
            $block->ip,
            $block->expiresAt === null ? 'until unblocked' : 'until ' . $time($block->expiresAt),
        ));
        exit(0);
    }

    if ($command === 'unblock') {
        $lifted = $blocks->unblock((string) $ip, $now);
        fwrite(STDOUT, $lifted === null
            ? "ip: {$ip} had no active block\n"
            : sprintf("ip: unblocked %s (was %s)\n", $lifted->ip, $describe($lifted)));
        exit(0);
    }

    $active = $blocks->active($now);
    if ($active === []) {
        fwrite(STDOUT, "ip: no active blocks\n");
        exit(0);
    }
    foreach ($active as $block) {
        fwrite(STDOUT, $describe($block) . "\n");
    }
    $forever = count(array_filter($active, static fn (IpBlock $block): bool => $block->expiresAt === null));
    fwrite(STDOUT, sprintf(
        "ip: %d active block(s), %d manual without an expiry%s\n",
        count($active),
        $forever,
        $forever === 0 ? '' : ' (review them: they keep a raw IP until unblocked)',
    ));
    exit(0);
} catch (Throwable $e) {
    // The class only: a message could name the database path or other local details.
    fwrite(STDERR, sprintf("ip: failed: %s\n", $e::class));
    exit(1);
}
