<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Authorization\Identity\AuthzVersionFreshnessGuard;
use Vortos\Foundation\DependencyInjection\Compiler\ConditionalWiringPass;

/**
 * Plugs the authorization version-freshness guard into vortos-auth's composite token-freshness
 * guard, when vortos-auth is installed.
 *
 * This mutates a service owned by ANOTHER package (vortos-auth), so the decision cannot live in
 * AuthorizationExtension::load() — during MergeExtensionConfigurationPass the auth extension may
 * not have registered CompositeTokenFreshnessGuard yet, making the has() order-dependent. In a
 * compiler pass has() is reliable and order-independent.
 */
final class AuthzTokenFreshnessWiringPass extends ConditionalWiringPass
{
    private const AUTH_COMPOSITE_GUARD = 'Vortos\\Auth\\TokenFreshness\\CompositeTokenFreshnessGuard';

    protected function wire(ContainerBuilder $container): void
    {
        if (!$this->optionalCapability($container, self::AUTH_COMPOSITE_GUARD)) {
            return;
        }

        $container->getDefinition(self::AUTH_COMPOSITE_GUARD)
            ->addArgument(new Reference(AuthzVersionFreshnessGuard::class));
    }
}
