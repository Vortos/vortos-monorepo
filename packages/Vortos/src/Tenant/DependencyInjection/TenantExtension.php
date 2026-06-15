<?php

declare(strict_types=1);

namespace Vortos\Tenant\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\Tenant\TenantContext;

/**
 * Registers the ambient {@see TenantContext}.
 *
 * It is a single shared, public service so the auth middleware can populate it
 * and the persistence layer can read it. It implements ResetInterface, so
 * ResettableServicesPass clears it at the end of every request automatically.
 */
final class TenantExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_tenant';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(TenantContext::class, TenantContext::class)
            ->setShared(true)
            ->setPublic(true);
    }
}
