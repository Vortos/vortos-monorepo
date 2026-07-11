<?php

declare(strict_types=1);

namespace Vortos\Audit\Admin;

use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;

/**
 * Audit permissions. `.own` = scoped to the caller's tenant (an org admin reading their
 * own trail); `.any` = cross-tenant/platform. Reading and exporting are split from
 * integrity verification and retention administration so a compliance viewer can be
 * granted read/export without the power to run destructive retention.
 */
#[PermissionCatalog(resource: 'audit', group: 'Audit')]
final class AuditPermissionCatalog extends AbstractPermissionCatalog
{
    public static function grants(): array
    {
        return [
            'audit.read.own'    => ['ROLE_ORG_ADMIN', 'ROLE_AUDIT_VIEWER', 'ROLE_SUPER_ADMIN'],
            'audit.read.any'    => ['ROLE_AUDIT_VIEWER', 'ROLE_SUPER_ADMIN'],
            'audit.export.own'  => ['ROLE_ORG_ADMIN', 'ROLE_AUDIT_VIEWER', 'ROLE_SUPER_ADMIN'],
            'audit.export.any'  => ['ROLE_AUDIT_VIEWER', 'ROLE_SUPER_ADMIN'],
            'audit.verify.any'  => ['ROLE_SUPER_ADMIN'],
            'audit.admin.any'   => ['ROLE_SUPER_ADMIN'],
        ];
    }

    public static function meta(): array
    {
        return [
            'audit.read.own'   => static::policyRequired('Read own-tenant audit trail'),
            'audit.read.any'   => static::describe('Read any tenant / platform audit trail'),
            'audit.export.own' => static::policyRequired('Export own-tenant audit trail'),
            'audit.export.any' => static::describe('Export any tenant / platform audit trail', dangerous: true),
            'audit.verify.any' => static::describe('Verify audit chain integrity'),
            'audit.admin.any'  => static::describe('Administer audit retention and settings', dangerous: true),
        ];
    }
}
