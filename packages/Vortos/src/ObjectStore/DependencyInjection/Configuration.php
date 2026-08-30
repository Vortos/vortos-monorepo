<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_object_store');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('driver')->defaultValue('log')->end()
                ->scalarNode('provider')->defaultValue('r2')->end()
                ->scalarNode('region')->defaultValue('auto')->end()
                ->arrayNode('client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('endpoint')->defaultNull()->end()
                        ->scalarNode('account_id')->defaultNull()->end()
                        ->scalarNode('access_key_id')->defaultNull()->end()
                        ->scalarNode('secret_access_key')->defaultNull()->end()
                        ->floatNode('http_timeout')->defaultValue(10.0)->end()
                        ->floatNode('connect_timeout')->defaultValue(2.0)->end()
                        ->integerNode('max_retries')->defaultValue(3)->end()
                        ->booleanNode('path_style_endpoint')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('bucket')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->defaultValue('')->end()
                        // A second bucket for write-ahead-log segments, when they must not share the
                        // primary one. The case that forces it: Object Lock is a bucket-level setting,
                        // and it is right for restore points and wrong for WAL — a segment lands every
                        // few minutes and pruning old ones is the whole point, which immutability
                        // forbids. Empty means WAL lives with everything else.
                        ->scalarNode('wal_name')->defaultValue('')->end()
                        // Restore points (base backups, logical dumps) in a bucket of their own,
                        // separate from BOTH the application's uploads and from WAL. Sharing a
                        // bucket with user uploads means one credential reaches both, so an
                        // application compromise can read every backup; and it forces the Object
                        // Lock rule to be prefix-conditional, where getting the prefix wrong either
                        // freezes user uploads or silently unlocks the backups. Empty means restore
                        // points live in the primary bucket, as before.
                        ->scalarNode('backups_name')->defaultValue('')->end()
                        ->scalarNode('key_prefix')->defaultValue('')->end()
                        ->scalarNode('temporary_key_prefix')->defaultValue('tmp')->end()
                        ->scalarNode('public_base_url')->defaultNull()->end()
                        ->integerNode('max_upload_size_bytes')->defaultValue(5368709120)->end()
                        ->integerNode('default_presign_ttl_seconds')->defaultValue(900)->end()
                        ->integerNode('max_presign_ttl_seconds')->defaultValue(3600)->end()
                        ->integerNode('orphan_ttl_seconds')->defaultValue(86400)->end()
                    ->end()
                ->end()
                ->arrayNode('retry')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_attempts')->defaultValue(3)->end()
                        ->integerNode('backoff_base_milliseconds')->defaultValue(100)->end()
                        ->integerNode('backoff_cap_milliseconds')->defaultValue(2000)->end()
                    ->end()
                ->end()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('table_name')->defaultValue('object_store_audit_log')->end()
                    ->end()
                ->end()
                ->arrayNode('multipart')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('threshold_bytes')->defaultValue(104857600)->end()
                        ->integerNode('part_size_bytes')->defaultValue(16777216)->end()
                        ->booleanNode('abort_on_failure')->defaultTrue()->end()
                        ->integerNode('max_object_size_bytes')->defaultValue(5497558138880)->end()
                        ->integerNode('max_inline_body_bytes')->defaultValue(16777216)->end()
                        ->integerNode('max_attempts')->defaultValue(3)->end()
                        ->integerNode('concurrency')->defaultValue(4)->end()
                        ->integerNode('backoff_base_milliseconds')->defaultValue(100)->end()
                        ->integerNode('backoff_cap_milliseconds')->defaultValue(2000)->end()
                        ->scalarNode('checksum_algorithm')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('outbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('table_name')->defaultValue('object_store_outbox')->end()
                        ->integerNode('batch_size')->defaultValue(50)->end()
                        ->integerNode('sleep_seconds_when_empty')->defaultValue(2)->end()
                        ->integerNode('max_delivery_attempts')->defaultValue(5)->end()
                        ->integerNode('backoff_base_seconds')->defaultValue(30)->end()
                        ->integerNode('backoff_cap_seconds')->defaultValue(3600)->end()
                        ->integerNode('max_inline_payload_bytes')->defaultValue(1048576)->end()
                    ->end()
                ->end()
                ->arrayNode('lifecycle')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('manage_temporary_uploads')->defaultTrue()->end()
                        ->scalarNode('rule_id')->defaultValue('vortos-object-store-expire-temporary-uploads')->end()
                        ->booleanNode('require_confirmation')->defaultTrue()->end()
                        ->booleanNode('round_up_minimum_lifecycle_day')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('circuit_breaker')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('failure_threshold')->defaultValue(5)->end()
                        ->integerNode('reset_timeout_seconds')->defaultValue(60)->end()
                    ->end()
                ->end()
                ->arrayNode('health')
                    ->addDefaultsIfNotSet()
                    ->info('Monitoring-probe cold-start resilience (see S3ObjectStoreHealthCheck).')
                    ->children()
                        ->integerNode('cold_start_attempts')
                            ->min(1)
                            ->defaultValue(3)
                            ->info('HeadBucket attempts before reporting unhealthy; absorbs a cold-connection blip on a freshly-started worker. Each attempt is a billed Class B operation on metered providers such as R2.')
                        ->end()
                        ->integerNode('cold_start_backoff_milliseconds')
                            ->min(0)
                            ->defaultValue(200)
                            ->info('Backoff between attempts. Total added latency stays well within the probe timeout budget.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('observability')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('logging')->defaultTrue()->end()
                        ->booleanNode('tracing')->defaultTrue()->end()
                        ->booleanNode('metrics')->defaultTrue()->end()
                        ->arrayNode('logging_disabled_for')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('tracing_disabled_for')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('metrics_disabled_for')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
