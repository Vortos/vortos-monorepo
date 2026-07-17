<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Export\AuditExportService;
use Vortos\AuditAdmin\Http\Controller\OrgAuditExportController;
use Vortos\AuditAdmin\Http\Controller\OrgAuditExportListController;
use Vortos\AuditAdmin\Http\Controller\OrgAuditExportStatusController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditExportController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditExportStatusController;

/**
 * Registers the async-export HTTP controllers once {@see AuditExportService} exists — i.e. after
 * {@see \Vortos\Audit\DependencyInjection\Compiler\AuditExportObjectStorePass} has confirmed an
 * object-store target and wired the service. Without an object store there is nothing to export,
 * so these endpoints simply don't mount (a request 404s at the router, the honest outcome).
 *
 * Runs at a lower priority than the vortos-audit export pass so the service definition is present
 * when this pass checks for it.
 */
final class AuditExportControllerPass implements CompilerPassInterface
{
    private const CURRENT_USER    = 'Vortos\Auth\Identity\CurrentUserProvider';
    private const TENANT_CONTEXT  = 'Vortos\Tenant\TenantContext';

    public function process(ContainerBuilder $container): void
    {
        if (!class_exists('Vortos\Http\Attribute\AsController')) {
            return;
        }
        if (!$container->hasDefinition(AuditExportService::class) && !$container->hasAlias(AuditExportService::class)) {
            return; // no export target wired
        }

        $exportsRef  = new Reference(AuditExportService::class);
        $hasIdentity = class_exists(self::CURRENT_USER) && ($container->has(self::CURRENT_USER) || $container->hasDefinition(self::CURRENT_USER));
        $hasTenant   = class_exists(self::TENANT_CONTEXT) && ($container->has(self::TENANT_CONTEXT) || $container->hasDefinition(self::TENANT_CONTEXT));

        // ── Platform (.any) ──────────────────────────────────────────────────────────
        if ($hasIdentity) {
            $container->register(PlatformAuditExportController::class, PlatformAuditExportController::class)
                ->setArgument('$exports', $exportsRef)
                ->setArgument('$currentUser', new Reference(self::CURRENT_USER))
                ->addTag('vortos.api.controller')
                ->setPublic(true);
        }

        $container->register(PlatformAuditExportStatusController::class, PlatformAuditExportStatusController::class)
            ->setArgument('$exports', $exportsRef)
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // ── Org (.own) — needs tenant context ────────────────────────────────────────
        if ($hasTenant) {
            $tenantRef = new Reference(self::TENANT_CONTEXT);

            if ($hasIdentity) {
                $container->register(OrgAuditExportController::class, OrgAuditExportController::class)
                    ->setArgument('$exports', $exportsRef)
                    ->setArgument('$currentUser', new Reference(self::CURRENT_USER))
                    ->setArgument('$tenantContext', $tenantRef)
                    ->addTag('vortos.api.controller')
                    ->setPublic(true);
            }

            $container->register(OrgAuditExportStatusController::class, OrgAuditExportStatusController::class)
                ->setArgument('$exports', $exportsRef)
                ->setArgument('$tenantContext', $tenantRef)
                ->addTag('vortos.api.controller')
                ->setPublic(true);

            $container->register(OrgAuditExportListController::class, OrgAuditExportListController::class)
                ->setArgument('$exports', $exportsRef)
                ->setArgument('$tenantContext', $tenantRef)
                ->addTag('vortos.api.controller')
                ->setPublic(true);
        }
    }
}
