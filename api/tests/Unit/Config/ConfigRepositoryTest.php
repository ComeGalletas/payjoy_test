<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Config;

use FxInstallments\Config\ConfigRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ConfigRepositoryTest extends TestCase
{
    public function testDefaultsHoldEverySection6Key(): void
    {
        $config = new ConfigRepository();

        self::assertSame(1, $config->int('installments.default'));
        self::assertSame(60, $config->int('installments.max'));
        self::assertSame(1000000, $config->int('amount.max'));
        self::assertSame(3, $config->int('bot.max_retries'));
        self::assertSame(30, $config->int('bot.session_ttl_minutes'));
        self::assertSame('static', $config->string('bot.frontend'));
        self::assertSame(48, $config->int('rates.max_stale_hours'));
        self::assertSame(30, $config->int('ratelimit.per_minute'));
        self::assertSame([], $config->stringList('cors.allowed_origins'));
        self::assertSame('America/Bogota', $config->string('reports.timezone'));
        self::assertSame(60, $config->int('health.quota_cache_minutes'));
        self::assertFalse($config->bool('llm.enabled'));
        self::assertSame(0.8, $config->float('llm.confidence_threshold'));
        self::assertSame(30, $config->int('requests.retention_days'));

        self::assertCount(14, $config->keys());
    }

    public function testAWrongTypeFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConfigRepository())->string('installments.max');
    }

    public function testAnUnknownKeyFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConfigRepository())->int('installments.maximum');
    }

    public function testOverridesReplaceKnownKeysOnly(): void
    {
        $config = new ConfigRepository(['ratelimit.per_minute' => 5, 'cors.allowed_origins' => ['https://shop.example']]);
        self::assertSame(5, $config->int('ratelimit.per_minute'));
        self::assertSame(['https://shop.example'], $config->stringList('cors.allowed_origins'));

        $this->expectException(InvalidArgumentException::class);
        new ConfigRepository(['no.such.key' => 1]);
    }

    public function testAStringListMustHoldOnlyStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConfigRepository(['cors.allowed_origins' => ['https://a.example', 5]]))->stringList('cors.allowed_origins');
    }

    public function testThereIsNoWritePath(): void
    {
        $public = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ConfigRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        self::assertSame(['__construct', 'bool', 'defaults', 'float', 'int', 'keys', 'string', 'stringList'], $public);
    }
}
