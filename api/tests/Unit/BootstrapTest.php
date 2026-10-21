<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use FxInstallments\Bootstrap;
use FxInstallments\Env;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    use OverridesEnvironment;

    public function testBootstrapSetsSerializePrecisionAndUtc(): void
    {
        self::assertSame('-1', ini_get('serialize_precision'));
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testDotenvIsSkippedEntirelyWhenAppEnvIsTest(): void
    {
        $dir = self::dirWithDotenv("FX_BOOTSTRAP_SENTINEL=loaded\n");
        $this->setEnvironment('FX_BOOTSTRAP_SENTINEL', null);

        self::assertTrue(Env::isTest());
        self::assertFalse(Bootstrap::loadDotenv($dir));
        self::assertNull(Env::get('FX_BOOTSTRAP_SENTINEL'));
    }

    public function testDotenvIsSkippedEvenWhenTheFileIsUnreadable(): void
    {
        // A directory named .env cannot be parsed; skipping must not even try.
        $dir = TestEnvironment::tempDir('dotenv-dir');
        mkdir($dir . '/.env');

        self::assertFalse(Bootstrap::loadDotenv($dir));
    }

    public function testOutsideTestsDotenvLoadsWithoutOverridingTheEnvironment(): void
    {
        $dir = self::dirWithDotenv("FX_BOOTSTRAP_SENTINEL=loaded\nFX_BOOTSTRAP_PRESET=from-file\n");
        $this->setEnvironment('APP_ENV', null);
        $this->setEnvironment('FX_BOOTSTRAP_SENTINEL', null);
        $this->setEnvironment('FX_BOOTSTRAP_PRESET', 'from-process');

        self::assertTrue(Bootstrap::loadDotenv($dir));
        self::assertSame('loaded', Env::get('FX_BOOTSTRAP_SENTINEL'));
        self::assertSame('from-process', Env::get('FX_BOOTSTRAP_PRESET'));
    }

    public function testAMissingDotenvIsFine(): void
    {
        $this->setEnvironment('APP_ENV', null);

        self::assertTrue(Bootstrap::loadDotenv(TestEnvironment::tempDir('no-dotenv')));
    }

    private static function dirWithDotenv(string $contents): string
    {
        $dir = TestEnvironment::tempDir('dotenv');
        file_put_contents($dir . '/.env', $contents);

        return $dir;
    }
}
