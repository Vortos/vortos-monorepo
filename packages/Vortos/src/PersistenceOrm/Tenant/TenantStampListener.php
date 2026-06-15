<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tenant;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Vortos\Tenant\TenantContext;

/**
 * Stamps the tenant column on new #[TenantScoped] entities at prePersist —
 * Layer 2 (writes) of tenant isolation on the ORM path.
 *
 * Writes always require a concrete tenant: {@see TenantContext::requireTenantId()}
 * throws when none is established (including system scope), so a tenant-scoped
 * row can never be inserted unattributed. Reads are scoped separately by
 * {@see TenantFilter}; the RLS WITH CHECK clause (Layer 3) is the final backstop.
 *
 * Scoped-entity membership comes from {@see TenantScopedEntityRegistry}
 * (precomputed at compile time) — an O(1) lookup, no reflection on the hot path.
 */
final class TenantStampListener
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $column = TenantScopedEntityRegistry::columnFor($entity::class);

        if ($column === null) {
            return;
        }

        $meta  = $args->getObjectManager()->getClassMetadata($entity::class);
        $field = $meta->getFieldForColumn($column);

        $meta->setFieldValue($entity, $field, $this->tenantContext->requireTenantId());
    }
}
