<?php

declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Adapter\RedisAdapter;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Cache\DependencyInjection\CacheExtension;

final class CacheExtensionEnvDefaultsTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (['VORTOS_CACHE_DRIVER', 'VORTOS_CACHE_DSN', 'VORTOS_CACHE_PREFIX'] as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
        }
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

    public function test_extension_uses_env_defaults_without_project_cache_config(): void
    {
        $_ENV['VORTOS_CACHE_DRIVER'] = 'redis';
        $_ENV['VORTOS_CACHE_DSN'] = 'redis://redis:6379';
        $_ENV['VORTOS_CACHE_PREFIX'] = 'dev_demo_';

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_cache_config');
        $container->setParameter('kernel.env', 'dev');

        (new CacheExtension())->load([], $container);

        $expectedDriver = extension_loaded('redis') ? RedisAdapter::class : InMemoryAdapter::class;

        $this->assertSame($expectedDriver, (string) $container->getAlias(CacheInterface::class));
        $this->assertSame($expectedDriver, (string) $container->getAlias(TaggedCacheInterface::class));
    }
}
