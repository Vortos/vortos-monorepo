<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection\Compiler;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\AwsSes\Command\Make\MakeBounceHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeComplaintHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeSesEmailMiddlewareCommand;
use Vortos\AwsSes\Deduplication\DeduplicationStoreInterface;
use Vortos\AwsSes\Deduplication\RedisDeduplicationStore;
use Vortos\AwsSes\RateLimit\RedisTokenBucket;
use Vortos\AwsSes\RateLimit\TokenBucketInterface;
use Vortos\AwsSes\Webhook\SnsSignatureVerifier;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Make\Engine\GeneratorEngine;

/**
 * Wires AwsSes dependencies on services owned by other packages.
 *
 * AwsSesExtension::load() cannot see \Redis, CacheInterface or GeneratorEngine,
 * because a has() check inside an Extension::load() runs against the isolated
 * per-extension container built by MergeExtensionConfigurationPass — those
 * services (registered by Cache/Auth/Make extensions) are never visible there
 * regardless of package load order. As a result the in-memory rate-limit/dedup
 * fallbacks were always used, the SNS cert fetcher was never cached, and the
 * vortos:ses:make:* commands were never registered. This pass applies all three
 * once the fully merged container is known.
 */
final class AwsSesRuntimeDependenciesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(\Redis::class)) {
            $this->wireRedisRateLimitAndDeduplication($container);
        }

        if ($container->has(CacheInterface::class)
            && $container->hasDefinition('vortos_aws_ses.sns_cert_fetcher')) {
            $this->wireCachedCertFetcher($container);
        }

        if ($container->has(GeneratorEngine::class)) {
            $this->wireMakeCommands($container);
        }
    }

    private function wireRedisRateLimitAndDeduplication(ContainerBuilder $container): void
    {
        $container->register(RedisTokenBucket::class, RedisTokenBucket::class)
            ->setArguments([
                new Reference(\Redis::class),
                '%vortos_aws_ses.rate_limit.max_send_rate%',
                '%vortos_aws_ses.rate_limit.burst%',
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(TokenBucketInterface::class, RedisTokenBucket::class)->setPublic(false);

        $container->register(RedisDeduplicationStore::class, RedisDeduplicationStore::class)
            ->setArgument('$cache', new Reference(AtomicCacheInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(DeduplicationStoreInterface::class, RedisDeduplicationStore::class)->setPublic(false);
    }

    private function wireCachedCertFetcher(ContainerBuilder $container): void
    {
        $container->getDefinition('vortos_aws_ses.sns_cert_fetcher')
            ->setFactory([SnsSignatureVerifier::class, 'cachedCertFetcher'])
            ->setArguments([new Reference(CacheInterface::class)]);
    }

    private function wireMakeCommands(ContainerBuilder $container): void
    {
        foreach ([
            MakeSesEmailMiddlewareCommand::class,
            MakeBounceHandlerCommand::class,
            MakeComplaintHandlerCommand::class,
        ] as $commandClass) {
            $container->register($commandClass, $commandClass)
                ->setArgument('$engine', new Reference(GeneratorEngine::class))
                ->addTag('console.command')
                ->setShared(true)
                ->setPublic(false);
        }
    }
}
