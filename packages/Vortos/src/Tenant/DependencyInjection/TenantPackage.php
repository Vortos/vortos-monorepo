<?php

declare(strict_types=1);

namespace Vortos\Tenant\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * Tenant isolation package.
 *
 * Registers the ambient TenantContext. The persistence scoping and RLS binding
 * live in the persistence adapters (which depend on this package), and the HTTP
 * population middleware lives in vortos-auth.
 *
 * Loads early (order 12) so TenantContext exists before persistence (15) and
 * auth (100) wire against it.
 */
final class TenantPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new TenantExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // No compiler passes — ResettableServicesPass picks up TenantContext
        // automatically via ResetInterface.
    }
}
