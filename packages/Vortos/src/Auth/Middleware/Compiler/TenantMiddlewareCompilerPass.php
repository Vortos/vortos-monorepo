<?php

declare(strict_types=1);

namespace Vortos\Auth\Middleware\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Tenant\TenantContextMiddleware;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

/**
 * Registers {@see TenantContextMiddleware} only when the tenant package is present.
 *
 * This lives in a compiler pass (registered by AuthPackage::build at a priority
 * above RegisterMiddlewarePass) rather than AuthExtension::load, because a
 * has(TenantContext::class) check inside an Extension::load runs against the
 * isolated per-extension container created by MergeExtensionConfigurationPass —
 * where TenantContext (owned by vortos-tenant) is never present, so the guard is
 * always false regardless of package load order. A compiler pass sees the fully
 * merged container, where the check is valid.
 */
final class TenantMiddlewareCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TenantContext::class)
            || $container->hasDefinition(TenantContextMiddleware::class)) {
            return;
        }

        $tenantClaim = $container->hasParameter('vortos.auth.tenant_claim')
            ? (string) $container->getParameter('vortos.auth.tenant_claim')
            : 'org_id';

        $container->register(TenantContextMiddleware::class, TenantContextMiddleware::class)
            ->setArguments([
                new Reference(CurrentUserProvider::class),
                new Reference(TenantContext::class),
                $tenantClaim,
                new Reference(TenantGucBinderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');
    }
}
