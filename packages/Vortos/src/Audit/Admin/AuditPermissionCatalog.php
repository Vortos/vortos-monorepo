<?php

declare(strict_types=1);

namespace Vortos\Audit\Admin;

use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;

/**
 * Audit permissions. Declared as public constants (action.scope; the 'audit' resource is
 * prefixed by the registry → e.g. 'audit.read.own'). grants() maps a ROLE to the
 * permissions it receives by default.
 *
 * `.own` = the caller's own tenant, `.any` = cross-tenant/platform. Tenant scoping for
 * `.own` is enforced in the controllers (queries are forced to the caller's tenant), so
 * no resource policy is required. Read/export are split from integrity verification and
 * retention administration. Cross-tenant (.any) + verify + admin grants are assigned to
 * the host app's platform/superadmin role by that app's own catalog.
 */
#[PermissionCatalog(resource: 'audit', group: 'Audit')]
final class AuditPermissionCatalog extends AbstractPermissionCatalog
{
    public const READ_OWN   = 'read.own';
    public const READ_ANY   = 'read.any';
    public const EXPORT_OWN = 'export.own';
    public const EXPORT_ANY = 'export.any';
    public const VERIFY_ANY = 'verify.any';
    public const ADMIN_ANY  = 'admin.any';

    public static function meta(): array
    {
        return [
            self::READ_OWN   => self::describe('Read own-tenant audit trail'),
            self::READ_ANY   => self::describe('Read any tenant / platform audit trail'),
            self::EXPORT_OWN => self::describe('Export own-tenant audit trail'),
            self::EXPORT_ANY => self::dangerous('Export any tenant / platform audit trail'),
            self::VERIFY_ANY => self::describe('Verify audit chain integrity'),
            self::ADMIN_ANY  => self::dangerous('Administer audit retention and settings'),
        ];
    }

    public static function grants(): array
    {
        // Org admins get their own-tenant read + export. The cross-tenant (.any), verify,
        // and admin permissions are granted to the platform/superadmin role by the host
        // app's platform catalog (mirroring how framework flag permissions are granted).
        return [
            'admin' => [self::READ_OWN, self::EXPORT_OWN],
        ];
    }
}
