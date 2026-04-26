<?php
declare(strict_types=1);
namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Auth\Storage\RedisTokenStorage;

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
                ->booleanNode('enable_built_in_controllers')
                    ->defaultFalse()
                    ->info('using built in login/token refresh/logout controllers')
                ->end()
            ->end();

        return $treeBuilder;
    }
}