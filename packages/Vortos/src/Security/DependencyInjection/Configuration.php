<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Vortos\Security\Masking\Strategy\MaskPartialStrategy;
use Vortos\Security\Secrets\EnvSecretsProvider;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_security');
        $root = $treeBuilder->getRootNode();

        $root->children()

            // -----------------------------------------------------------------
            // HTTP Security Headers
            // -----------------------------------------------------------------
            ->arrayNode('headers')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('hsts')->defaultFalse()->end()
                    ->integerNode('hsts_max_age')->defaultValue(31536000)->end()
                    ->booleanNode('hsts_sub_domains')->defaultTrue()->end()
                    ->booleanNode('hsts_preload')->defaultFalse()->end()
                    ->scalarNode('x_frame_options')->defaultValue('DENY')->end()
                    ->booleanNode('x_content_type_nosniff')->defaultTrue()->end()
                    ->scalarNode('referrer_policy')->defaultValue('strict-origin-when-cross-origin')->end()
                    ->arrayNode('permissions_policy')
                        ->useAttributeAsKey('feature')
                        ->arrayPrototype()->scalarPrototype()->end()->end()
                    ->end()
                    ->scalarNode('coep')->defaultValue('require-corp')->end()
                    ->scalarNode('coop')->defaultValue('same-origin')->end()
                    ->scalarNode('corp')->defaultValue('same-origin')->end()
                    ->arrayNode('csp')
                        ->canBeDisabled()
                        ->children()
                            ->arrayNode('default_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('script_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('style_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('img_src')->scalarPrototype()->end()->defaultValue(["'self'", 'data:'])->end()
                            ->arrayNode('font_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('connect_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('frame_src')->scalarPrototype()->end()->defaultValue(["'none'"])->end()
                            ->arrayNode('object_src')->scalarPrototype()->end()->defaultValue(["'none'"])->end()
                            ->arrayNode('media_src')->scalarPrototype()->end()->defaultValue(["'self'"])->end()
                            ->arrayNode('worker_src')->scalarPrototype()->end()->defaultValue(["'none'"])->end()
                            ->scalarNode('report_uri')->defaultValue('')->end()
                            ->scalarNode('report_to')->defaultValue('')->end()
                            ->booleanNode('report_only')->defaultFalse()->end()
                            ->arrayNode('extra')
                                ->useAttributeAsKey('directive')
                                ->arrayPrototype()->scalarPrototype()->end()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // CORS
            // -----------------------------------------------------------------
            ->arrayNode('cors')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('origins')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('methods')->scalarPrototype()->end()->defaultValue(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])->end()
                    ->arrayNode('allowed_headers')->scalarPrototype()->end()->defaultValue(['Content-Type', 'Authorization', 'X-Requested-With'])->end()
                    ->arrayNode('exposed_headers')->scalarPrototype()->end()->defaultValue([])->end()
                    ->booleanNode('credentials')->defaultFalse()->end()
                    ->integerNode('max_age')->defaultValue(3600)->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // CSRF
            // -----------------------------------------------------------------
            ->arrayNode('csrf')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->scalarNode('header_name')->defaultValue('X-CSRF-Token')->end()
                    ->scalarNode('cookie_name')->defaultValue('csrf_token')->end()
                    ->booleanNode('cookie_secure')->defaultFalse()->end()
                    ->scalarNode('cookie_same_site')->defaultValue('Strict')->end()
                    ->integerNode('token_length')->defaultValue(32)->end()
                    ->arrayNode('skip_controllers')->scalarPrototype()->end()->defaultValue([])->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // IP Filter
            // -----------------------------------------------------------------
            ->arrayNode('ip_filter')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->arrayNode('allowlist')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('denylist')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('trusted_proxies')->scalarPrototype()->end()->defaultValue(['127.0.0.1', '::1'])->end()
                    ->arrayNode('skip_controllers')->scalarPrototype()->end()->defaultValue([])->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // Password Policy
            // -----------------------------------------------------------------
            ->arrayNode('password_policy')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('min_length')->defaultValue(12)->end()
                    ->integerNode('max_length')->defaultValue(128)->end()
                    ->booleanNode('require_uppercase')->defaultTrue()->end()
                    ->booleanNode('require_lowercase')->defaultTrue()->end()
                    ->booleanNode('require_digit')->defaultTrue()->end()
                    ->booleanNode('require_special')->defaultTrue()->end()
                    ->booleanNode('check_common')->defaultTrue()->end()
                    ->booleanNode('hibp_enabled')->defaultFalse()->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // Encryption (opt-in)
            // -----------------------------------------------------------------
            ->arrayNode('encryption')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->scalarNode('master_key_env')->defaultValue('ENCRYPTION_KEY')->end()
                    ->scalarNode('algorithm')->defaultValue('aes-256-gcm')->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // Secrets Management (opt-in)
            // -----------------------------------------------------------------
            ->arrayNode('secrets')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('driver')->defaultValue(EnvSecretsProvider::class)->end()
                    ->scalarNode('vault_addr')->defaultValue('')->end()
                    ->scalarNode('vault_token')->defaultValue('')->end()
                    ->scalarNode('role_id')->defaultValue('')->end()
                    ->scalarNode('secret_id')->defaultValue('')->end()
                    ->integerNode('cache_ttl')->defaultValue(300)->end()
                    ->scalarNode('aws_region')->defaultValue('')->end()
                    ->scalarNode('aws_prefix')->defaultValue('')->end()
                ->end()
            ->end()

            // -----------------------------------------------------------------
            // Data Masking (opt-in)
            // -----------------------------------------------------------------
            ->arrayNode('data_masking')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->scalarNode('default_strategy')->defaultValue(MaskPartialStrategy::class)->end()
                    ->booleanNode('all_channels')->defaultFalse()->end()
                ->end()
            ->end()

        ->end();

        return $treeBuilder;
    }
}
