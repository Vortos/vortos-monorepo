<?php

declare(strict_types=1);

namespace Vortos\Audit\SavedView;

/**
 * Persistence for console saved views. Scope-bound reads: a caller only ever lists views
 * for their own (tenantId, ownerId) pair, so one org can't enumerate another's saved views.
 */
interface AuditSavedViewStoreInterface
{
    public function save(AuditSavedView $view): void;

    /**
     * All views owned by $ownerId within $tenantId (null = platform scope), newest first.
     *
     * @return list<AuditSavedView>
     */
    public function listFor(?string $tenantId, string $ownerId): array;

    /** Delete one view, but only if it belongs to (tenantId, ownerId). Returns true if a row was removed. */
    public function delete(string $id, ?string $tenantId, string $ownerId): bool;
}
