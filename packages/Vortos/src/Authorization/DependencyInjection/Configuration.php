<?php
declare(strict_types=1);
namespace Vortos\Authorization\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_authorization');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('role_hierarchy')
                    ->useAttributeAsKey('role')
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                    ->defaultValue([])
                    ->info('Role inheritance map. PARENT_ROLE => [CHILD_ROLE_1, CHILD_ROLE_2]')
                ->end()
                ->booleanNode('authz_version_check')
                    ->defaultTrue()
                    ->info('Reject authenticated identities whose authz_version claim is older than the runtime authorization version.')
                ->end()
                ->booleanNode('break_glass_bypass')
                    ->defaultFalse()
                    ->info('Allow the configured break-glass role to bypass resolver and policy checks for catalog permissions explicitly marked bypassable.')
                ->end()
                ->scalarNode('break_glass_role')
                    ->defaultValue('ROLE_SUPER_ADMIN')
                    ->info('Role used for optional break-glass authorization bypass.')
                ->end()
                ->booleanNode('trace_decisions')
                    ->defaultFalse()
                    ->info('Trace authorization engine decisions when the tracing module is installed.')
                ->end()
                ->booleanNode('trace_resolver')
                    ->defaultFalse()
                    ->info('Trace permission resolver and permission cache work when the tracing module is installed.')
                ->end()
                ->booleanNode('trace_admin_mutations')
                    ->defaultFalse()
                    ->info('Trace authorization admin mutation service calls when the tracing module is installed.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
