<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

final class PermissionMetadata
{
    public function __construct(
        public readonly string $permission,
        public readonly string $resource,
        public readonly string $action,
        public readonly string $scope,
        public readonly string $label,
        public readonly ?string $description,
        public readonly bool $dangerous,
        public readonly bool $bypassable,
        public readonly ?string $group,
        public readonly string $catalogClass,
        /**
         * When true, this permission ALWAYS requires a resource policy. With no policy
         * registered the engine fails closed (PolicyRequired) regardless of scope kind —
         * defense-in-depth for sensitive resources. Takes precedence over $selfEnforced.
         */
        public readonly bool $policyRequired = false,
        /**
         * When true, the relationship for this permission is deliberately enforced
         * elsewhere (e.g. inside the handler). With no policy the engine allows and
         * records the ExternallyEnforced reason so the bypass stays auditable.
         */
        public readonly bool $selfEnforced = false,
    ) {
    }
}
