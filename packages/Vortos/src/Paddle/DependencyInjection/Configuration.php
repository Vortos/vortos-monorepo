<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('vortos_paddle');
        $root = $tree->getRootNode();

        $root->children()
            ->scalarNode('mode')->defaultValue('sandbox')->end()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('notification_secret')->defaultValue('')->end()
            ->scalarNode('webhook_path')->defaultValue('/webhooks/paddle')->end()

            ->arrayNode('client')
                ->addDefaultsIfNotSet()
                ->children()
                    ->floatNode('http_timeout')->defaultValue(10.0)->end()
                    ->floatNode('connect_timeout')->defaultValue(3.0)->end()
                    ->integerNode('max_retries')->defaultValue(3)->end()
                    ->booleanNode('retry_on_rate_limit')->defaultTrue()->end()
                    ->integerNode('idempotency_key_ttl_seconds')->defaultValue(86400)->end()
                ->end()
            ->end()

            ->arrayNode('circuit_breaker')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->integerNode('failure_threshold')->defaultValue(5)->end()
                    ->integerNode('reset_timeout_seconds')->defaultValue(60)->end()
                ->end()
            ->end()

            ->arrayNode('security')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enforce_ip_allowlist')->defaultFalse()->end()
                    ->integerNode('replay_window_seconds')->defaultValue(5)->end()
                    ->booleanNode('allow_sandbox_ips')->defaultFalse()->end()
                ->end()
            ->end()

            ->arrayNode('webhooks')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->scalarNode('inbox_table')->defaultValue('paddle_webhook_inbox')->end()
                    ->integerNode('inbox_batch_size')->defaultValue(50)->end()
                    ->integerNode('inbox_max_attempts')->defaultValue(5)->end()
                    ->integerNode('backoff_base_seconds')->defaultValue(60)->end()
                    ->integerNode('backoff_cap_seconds')->defaultValue(3600)->end()
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

            ->arrayNode('outbox')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('batch_size')->defaultValue(50)->end()
                    ->integerNode('max_attempts')->defaultValue(5)->end()
                    ->integerNode('backoff_base_seconds')->defaultValue(60)->end()
                    ->integerNode('backoff_cap_seconds')->defaultValue(3600)->end()
                    ->integerNode('sleep_seconds_when_empty')->defaultValue(2)->end()
                ->end()
            ->end()
        ->end();

        return $tree;
    }
}
