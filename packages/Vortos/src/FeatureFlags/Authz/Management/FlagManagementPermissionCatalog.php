<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz\Management;

use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;

/**
 * Registers the permissions the flag management API (`/api/management/v1/flags/*`)
 * enforces via {@see PolicyEngineManagementAuthzGate}.
 *
 * Without this catalog the gate's `requirePermission('flags.read.any' | 'flags.write.any'
 * | 'flags.publish.any')` calls fail closed with `unknown_permission` — the strings are
 * referenced by the controllers but were never declared to the permission registry, so
 * no role could ever hold them. Shipping the catalog in the framework means every app that
 * installs feature-flags gets the management permissions registered for free; the app only
 * has to GRANT them to whichever role runs its admin plane (grants() is intentionally empty
 * here — the framework does not know an app's role names).
 *
 * All three are `.any` scope: admin-plane, global, no per-resource ownership. The scope
 * classifier treats `any` as SelfSufficient, so a straight RBAC grant is authoritative and
 * no resource policy is required.
 */
#[PermissionCatalog(resource: 'flags', group: 'Feature Flags')]
final class FlagManagementPermissionCatalog extends AbstractPermissionCatalog
{
    public const READ_ANY    = 'read.any';
    public const WRITE_ANY   = 'write.any';
    public const PUBLISH_ANY = 'publish.any';

    public static function meta(): array
    {
        return [
            self::READ_ANY    => self::describe(
                label: 'Read any feature flag',
                description: 'List and inspect every feature flag and its targeting across all environments.',
            ),
            self::WRITE_ANY   => self::describe(
                label: 'Write any feature flag',
                description: 'Create, enable/disable, and configure targeting rules, variants, rollout and schedule for any flag.',
                dangerous: true,
            ),
            self::PUBLISH_ANY => self::describe(
                label: 'Publish any feature flag',
                description: 'Promote a flag configuration from one environment to another.',
                dangerous: true,
            ),
        ];
    }
}
