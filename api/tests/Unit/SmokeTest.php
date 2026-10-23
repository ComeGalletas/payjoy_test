<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    private const FAKE_KEY = 'test-fake-key-not-real';

    /**
     * The PHP floor is composer.json's `config.platform.php` (ADR 0001 third amendment: 8.3.36,
     * owner decision 2026-10-22), so a release moves it with one edit. Older releases have
     * CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"); only an upgrade fixes them.
     */
    public function testPhpIsAtLeastThePlatformVersion(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        $minimum = $composer['config']['platform']['php'];
        self::assertMatchesRegularExpression('/^8\.3\.\d+$/D', $minimum, 'config.platform.php is a full 8.3 release');
        self::assertSame(
            '^' . $minimum,
            $composer['require']['php'],
            "api/composer.json require.php must start at config.platform.php ({$minimum}): set require.php to \"^{$minimum}\","
            . ' or, when the floor moves, change both values together and run composer update --lock.',
        );

        self::assertTrue(
            version_compare(PHP_VERSION, $minimum, '>='),
            sprintf(
                'PHP %s or newer is required, running %s: older releases have CVE-2026-109618 and CVE-2026-109619'
                . ' (remote code execution). Upgrade PHP, then run make check again (ADR 0001 third amendment).',
                $minimum,
                PHP_VERSION,
            ),
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
