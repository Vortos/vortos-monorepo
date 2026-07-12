<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\AuditAdmin\Http\Controller\OrgAuditController;
use Vortos\AuditAdmin\Http\Controller\OrgAuditExportController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditExportController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditVerifyController;

/**
 * Mounts the audit-admin HTTP API into the host vortos-http pipeline.
 *
 * Every controller is a thin adapter over the framework's {@see AuditAdminService}: it maps
 * request params to an AuditQuery / chainKey and serialises the result. All identity/tenant
 * semantics that the framework can express (scope, TenantContext tenantId, permission gates,
 * 2FA step-up) live in attributes on the controllers — nothing app-specific here, so any
 * Vortos app gets the same audit console API for free.
 *
 * Registered only when {@see AuditAdminService} is present (vortos-audit installed) and the
 * HTTP controller attribute exists (vortos-http installed).
 */
final class AuditAdminExtension extends Extension
{
    /** @var list<class-string> */
    private const PLATFORM_CONTROLLERS = [
        PlatformAuditController::class,
        PlatformAuditVerifyController::class,
        PlatformAuditExportController::class,
    ];

    /** @var list<class-string> */
    private const ORG_CONTROLLERS = [
        OrgAuditController::class,
        OrgAuditExportController::class,
    ];

    public function getAlias(): string
    {
        return 'vortos_audit_admin';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        if (!class_exists('Vortos\Http\Attribute\AsController')) {
            return;
        }

        $tenantContext = 'Vortos\Tenant\TenantContext';

        // Platform (.any) endpoints depend only on the admin service.
        foreach (self::PLATFORM_CONTROLLERS as $controller) {
            $container->register($controller, $controller)
                ->setArgument('$audit', new Reference(AuditAdminService::class))
                ->addTag('vortos.api.controller')
                ->setPublic(true);
        }

        // Org-own (.own) endpoints additionally resolve the caller's tenant from context.
        // Registered only when vortos-tenant is installed (else the app has no org scope).
        if (class_exists($tenantContext)) {
            foreach (self::ORG_CONTROLLERS as $controller) {
                $container->register($controller, $controller)
                    ->setArgument('$audit', new Reference(AuditAdminService::class))
                    ->setArgument('$tenantContext', new Reference($tenantContext))
                    ->addTag('vortos.api.controller')
                    ->setPublic(true);
            }
        }
    }
}
