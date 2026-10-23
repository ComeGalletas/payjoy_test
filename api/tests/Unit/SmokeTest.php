<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    private const FAKE_KEY = 'test-fake-key-not-real';

    /** PHP_VERSION_ID of 8.4.0: the first unsupported release (ADR 0001 fourth amendment). */
    private const CEILING_ID = 80400;

    /**
     * The PHP floor is composer.json's `config.platform.php` (ADR 0001 third amendment: 8.3.36,
     * owner decision 2026-10-22), so a release moves it with one edit. Older releases have
     * CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"); only an upgrade fixes them.
     *
     * The app supports PHP 8.3 only (ADR 0001 fourth amendment): `require.php` is `~` and the pin,
     * and the running PHP must be at least the pin and below 8.4.0. Composer's platform check
     * reads only the lower bound, so this test is what refuses 8.4 or later. composer/semver
     * isn't installed, so the range is checked by hand, on numbers: PHP_VERSION_ID against the
     * pin's ID and 80400. (version_compare ranks unknown suffixes below `dev`, so a string
     * check let `8.4.0-foo` and `8.4.0+deb12u1` through; round 1 of the cleanup B review.)
     */
    public function testPhpIsAPatchedEightThreeRelease(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        $minimum = $composer['config']['platform']['php'];
        self::assertMatchesRegularExpression('/^8\.3\.\d+$/D', $minimum, 'config.platform.php is a full 8.3 release');
        self::assertSame(
            '~' . $minimum,
            $composer['require']['php'],
            "api/composer.json require.php must be ~ followed by config.platform.php ({$minimum}):"
            . " set require.php to \"~{$minimum}\" (8.3 only, ADR 0001 fourth amendment), or, when the"
            . ' floor moves, change both values together and run composer update --lock.',
        );

        $minimumId = self::versionId($minimum);
        self::assertNotNull($minimumId);
        self::assertTrue(
            PHP_VERSION_ID >= $minimumId && PHP_VERSION_ID < self::CEILING_ID,
            self::unsupportedMessage(PHP_VERSION, $minimum),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function phpVersions(): iterable
    {
        yield 'the pin' => ['8.3.36', true];
        yield 'a later 8.3 release' => ['8.3.40', true];
        yield 'a later 8.3 dev build' => ['8.3.37-dev', true];
        yield 'the release before the pin' => ['8.3.35', false];
        yield 'an older branch' => ['8.2.35', false];
        yield '8.4.0' => ['8.4.0', false];
        yield '8.4.0 release candidate' => ['8.4.0RC1', false];
        yield 'a fixed 8.4 release' => ['8.4.27', false];
        yield '8.5' => ['8.5.12', false];
        yield '9.0' => ['9.0.0', false];
        // Round 1 of the cleanup B review: suffixes don't move the numbers.
        yield '8.4.0 with an unknown suffix' => ['8.4.0-foo', false];
        yield '8.4.0 with a Debian build suffix' => ['8.4.0+deb12u1', false];
        yield 'the pin with an Ubuntu build suffix' => ['8.3.36-1ubuntu1', true];
        yield 'no major.minor.patch prefix' => ['8.3', false];
        yield 'not a version' => ['latest', false];
    }

    /**
     * The range check itself, against versions the host doesn't run.
     */
    #[DataProvider('phpVersions')]
    public function testTheSupportedRangeIsThePinUpToEightFour(string $version, bool $supported): void
    {
        self::assertSame($supported, self::isSupported($version, '8.3.36'));
    }

    public function testTheFailureMessageNamesTheCvesAndEightThreeOnly(): void
    {
        self::assertSame(
            'PHP 8.3.36 or a later 8.3 release is required, running 8.4.27: the app supports PHP 8.3 only'
            . ' (ADR 0001 fourth amendment), and releases before 8.3.36 have CVE-2026-109618 and'
            . ' CVE-2026-109619 (remote code execution). Install a PHP 8.3 release from 8.3.36 on, then'
            . ' run make check again.',
            self::unsupportedMessage('8.4.27', '8.3.36'),
        );
    }

    /**
     * At least $minimum and below 8.4.0, comparing only the numeric `major.minor.patch` prefix
     * of each, as PHP_VERSION_ID does: any suffix (RC1, -dev, -foo, +deb12u1, -1ubuntu1) is
     * ignored, so every 8.4.0 build is refused. A string without that prefix is refused.
     */
    private static function isSupported(string $version, string $minimum): bool
    {
        $id = self::versionId($version);
        $minimumId = self::versionId($minimum);

        return $id !== null && $minimumId !== null && $id >= $minimumId && $id < self::CEILING_ID;
    }

    /**
     * The PHP_VERSION_ID of a version string's `major.minor.patch` prefix (8.3.36 → 80336), or
     * null when it has none.
     */
    private static function versionId(string $version): ?int
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?!\d)/', $version, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 10_000 + (int) $match[2] * 100 + (int) $match[3];
    }

    private static function unsupportedMessage(string $version, string $minimum): string
    {
        return sprintf(
            'PHP %1$s or a later 8.3 release is required, running %2$s: the app supports PHP 8.3 only'
            . ' (ADR 0001 fourth amendment), and releases before %1$s have CVE-2026-109618 and'
            . ' CVE-2026-109619 (remote code execution). Install a PHP 8.3 release from %1$s on, then'
            . ' run make check again.',
            $minimum,
            $version,
        );
    }

    #[DataProvider('requiredExtensions')]
    public function testRequiredExtensionIsLoaded(string $extension): void
    {
        self::assertTrue(extension_loaded($extension), "PHP extension '{$extension}' is not loaded");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredExtensions(): iterable
    {
        yield 'pdo_sqlite' => ['pdo_sqlite'];
        yield 'curl' => ['curl'];
        yield 'mbstring' => ['mbstring'];
        yield 'openssl' => ['openssl'];
    }

    public function testEnvironmentHoldsTheFakeApiKey(): void
    {
        self::assertSame(self::FAKE_KEY, getenv('EXCHANGERATE_API_KEY'));
        self::assertSame(self::FAKE_KEY, $_ENV['EXCHANGERATE_API_KEY'] ?? null);
        self::assertSame(self::FAKE_KEY, $_SERVER['EXCHANGERATE_API_KEY'] ?? null);
    }
}
