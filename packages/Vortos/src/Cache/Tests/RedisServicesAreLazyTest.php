<?php

declare(strict_types=1);

namespace Vortos\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\DependencyInjection\CacheExtension;
use Vortos\Cache\Health\RedisHealthCheck;

/**
 * G5 guard: when the Redis cache driver is active, the userland services that hold the \Redis
 * connection must be registered lazy. RedisConnectionFactory::fromDsn() connects eagerly on
 * construction, so if these were built at container boot the process could not boot without a live
 * Redis — fatal for deploy-in-image (operator/deploy commands run on an infra-less host). Laziness
 * defers the connect() to the first cache/health call: a command that never touches the cache never
 * opens a socket.
 */
final class RedisServicesAreLazyTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (['VORTOS_CACHE_DRIVER', 'VORTOS_CACHE_DSN'] as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['VORTOS_CACHE_DRIVER'] = 'redis';
        $_ENV['VORTOS_CACHE_DSN'] = 'redis://redis:6379';
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }
            $_ENV[$key] = $value;
        }
    }

    public function test_redis_holding_services_are_lazy(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis not loaded; redis driver falls back to in-memory.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_cache_config');
        $container->setParameter('kernel.env', 'dev');

        (new CacheExtension())->load([], $container);

        self::assertTrue(
            $container->getDefinition(RedisAdapter::class)->isLazy(),
            'RedisAdapter must be lazy so the eager fromDsn() connect defers to first cache use.',
        );
        self::assertTrue(
            $container->getDefinition(RedisHealthCheck::class)->isLazy(),
            'RedisHealthCheck must be lazy so booting it does not open a Redis connection.',
        );
    }
}
