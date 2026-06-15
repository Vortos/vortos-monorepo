<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tenant;

use Doctrine\ORM\EntityManagerInterface;
use Vortos\Tenant\TenantContext;

/**
 * Helpers for deliberate cross-tenant (system-scope) ORM access.
 *
 * The {@see TenantFilter} is enabled by default, so every read is tenant-scoped.
 * Genuine cross-tenant work — admin dashboards, reporting, migrations — must opt
 * out explicitly through {@see self::systemScope()}, which drops the read filter
 * for the closure and restores it afterwards (even on exception). This keeps the
 * normal request path branch-free and makes every cross-tenant read visible in
 * code review.
 *
 * At the database level, such work must run under a role granted BYPASSRLS (or a
 * permissive policy); dropping the Doctrine filter alone does not lift RLS.
 *
 *   $all = TenantOrm::systemScope($em, $tenantContext,
 *       fn() => $reportRepository->totalsAcrossAllTenants());
 */
final class TenantOrm
{
    /**
     * Run $fn with the tenant read filter disabled and the context in system
     * scope. Both are restored afterwards.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function systemScope(EntityManagerInterface $em, TenantContext $context, callable $fn): mixed
    {
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled(TenantFilter::NAME);

        if ($wasEnabled) {
            $filters->disable(TenantFilter::NAME);
        }

        try {
            return $context->runAsSystem($fn);
        } finally {
            if ($wasEnabled) {
                $filters->enable(TenantFilter::NAME);
            }
        }
    }
}
