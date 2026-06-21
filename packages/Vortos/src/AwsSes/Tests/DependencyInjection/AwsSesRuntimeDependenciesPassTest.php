<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\AwsSes\DependencyInjection\Compiler\AwsSesRuntimeDependenciesPass;
use Vortos\AwsSes\Deduplication\DeduplicationStoreInterface;
use Vortos\AwsSes\Deduplication\InMemoryDeduplicationStore;
use Vortos\AwsSes\Deduplication\RedisDeduplicationStore;
use Vortos\AwsSes\RateLimit\InMemoryTokenBucket;
use Vortos\AwsSes\RateLimit\RedisTokenBucket;
use Vortos\AwsSes\RateLimit\TokenBucketInterface;
use Vortos\AwsSes\Command\Make\MakeBounceHandlerCommand;
use Vortos\Make\Engine\GeneratorEngine;

final class AwsSesRuntimeDependenciesPassTest extends TestCase
{
    public function test_keeps_in_memory_defaults_when_nothing_present(): void
    {
        $container = $this->baseContainer();

        (new AwsSesRuntimeDependenciesPass())->process($container);

        $this->assertSame(InMemoryTokenBucket::class, (string) $container->getAlias(TokenBucketInterface::class));
        $this->assertSame(InMemoryDeduplicationStore::class, (string) $container->getAlias(DeduplicationStoreInterface::class));
        $this->assertFalse($container->hasDefinition(RedisTokenBucket::class));
        $this->assertFalse($container->hasDefinition(MakeBounceHandlerCommand::class));
    }

    public function test_swaps_to_redis_rate_limit_and_dedup_when_redis_present(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(\Redis::class, new Definition(\Redis::class));

        (new AwsSesRuntimeDependenciesPass())->process($container);

        $this->assertSame(RedisTokenBucket::class, (string) $container->getAlias(TokenBucketInterface::class));
        $this->assertSame(RedisDeduplicationStore::class, (string) $container->getAlias(DeduplicationStoreInterface::class));
    }

    public function test_swaps_cert_fetcher_to_cached_when_cache_present(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(CacheInterface::class, new Definition(\stdClass::class));

        (new AwsSesRuntimeDependenciesPass())->process($container);

        $factory = $container->getDefinition('vortos_aws_ses.sns_cert_fetcher')->getFactory();
        $this->assertSame('cachedCertFetcher', $factory[1]);
    }

    public function test_registers_make_commands_when_generator_engine_present(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(GeneratorEngine::class, new Definition(\stdClass::class));

        (new AwsSesRuntimeDependenciesPass())->process($container);

        $this->assertTrue($container->hasDefinition(MakeBounceHandlerCommand::class));
        $this->assertTrue($container->getDefinition(MakeBounceHandlerCommand::class)->hasTag('console.command'));
    }

    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(InMemoryTokenBucket::class, InMemoryTokenBucket::class);
        $container->setAlias(TokenBucketInterface::class, InMemoryTokenBucket::class);
        $container->register(InMemoryDeduplicationStore::class, InMemoryDeduplicationStore::class);
        $container->setAlias(DeduplicationStoreInterface::class, InMemoryDeduplicationStore::class);
        $container->register('vortos_aws_ses.sns_cert_fetcher', \Closure::class)
            ->setFactory(['X', 'defaultCertFetcher']);
        $container->setParameter('vortos_aws_ses.rate_limit.max_send_rate', 14);
        $container->setParameter('vortos_aws_ses.rate_limit.burst', 10);
        return $container;
    }
}
