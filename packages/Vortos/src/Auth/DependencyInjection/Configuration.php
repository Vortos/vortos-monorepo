<?php
declare(strict_types=1);
namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_auth');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('secret')
                    ->defaultValue('')
                    ->info('HMAC-SHA256 signing secret. Generate: bin2hex(random_bytes(32))')
                ->end()
                ->integerNode('access_token_ttl')
                    ->defaultValue(900)
                    ->info('Access token TTL in seconds. Default: 900 (15 minutes)')
                ->end()
                ->integerNode('refresh_token_ttl')
                    ->defaultValue(604800)
                    ->info('Refresh token TTL in seconds. Default: 604800 (7 days)')
                ->end()
                ->scalarNode('issuer')
                    ->defaultValue('vortos')
                    ->info('JWT issuer claim — use your app name or domain')
                ->end()
                ->scalarNode('token_storage')
                    ->defaultValue(InMemoryTokenStorage::class)
                    ->info('FQCN of TokenStorageInterface implementation')
                ->end()
                ->enumNode('quota_failure_mode')
                    ->values([QuotaFailureMode::FailClosed->value, QuotaFailureMode::FailOpen->value])
                    ->defaultValue(QuotaFailureMode::FailClosed->value)
                    ->info('Quota behavior when Redis is unavailable.')
                ->end()
                ->booleanNode('quota_headers')
                    ->defaultTrue()
                    ->info('Emit X-Quota-* headers on quota-protected responses.')
                ->end()
                ->booleanNode('rate_limit_headers')
                    ->defaultTrue()
                    ->info('Emit X-RateLimit-* headers on rate-limited responses.')
                ->end()
                ->booleanNode('problem_details')
                    ->defaultTrue()
                    ->info('Emit RFC 7807-style application/problem+json responses for auth policy failures.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
