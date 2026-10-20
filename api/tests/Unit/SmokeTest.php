<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    private const FAKE_KEY = 'test-fake-key-not-real';

    public function testPhpIsAtLeast83(): void
    {
        self::assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'PHP 8.3 or newer is required, running ' . PHP_VERSION,
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
