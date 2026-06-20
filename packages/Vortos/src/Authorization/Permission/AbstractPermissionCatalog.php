<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

abstract class AbstractPermissionCatalog implements PermissionCatalogInterface
{
    public static function grants(): array
    {
        return [];
    }

    public static function meta(): array
    {
        return [];
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool, policyRequired: bool, selfEnforced: bool}
     */
    protected static function describe(
        string $label,
        ?string $description = null,
        bool $dangerous = false,
        ?bool $bypassable = null,
        bool $policyRequired = false,
        bool $selfEnforced = false,
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'dangerous' => $dangerous,
            'bypassable' => $bypassable ?? false,
            'policyRequired' => $policyRequired,
            'selfEnforced' => $selfEnforced,
        ];
    }

    /**
     * Declare that this permission always requires a resource policy (fail closed
     * when none is registered, regardless of scope kind).
     *
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool, policyRequired: bool, selfEnforced: bool}
     */
    protected static function policyRequired(
        string $label,
        ?string $description = null,
        bool $dangerous = false,
    ): array {
        return self::describe($label, $description, $dangerous, false, policyRequired: true);
    }

    /**
     * Declare that the relationship for this permission is deliberately enforced
     * elsewhere (e.g. the handler). Allowed with no policy, recorded as
     * ExternallyEnforced for audit.
     *
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool, policyRequired: bool, selfEnforced: bool}
     */
    protected static function selfEnforced(
        string $label,
        ?string $description = null,
    ): array {
        return self::describe($label, $description, selfEnforced: true);
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool}
     */
    protected static function dangerous(string $label, ?string $description = null): array
    {
        return self::describe($label, $description, true, false);
    }

    /**
     * @return array{label: string, description: ?string, dangerous: bool, bypassable: bool}
     */
    protected static function bypassable(
        string $label,
        ?string $description = null,
        bool $dangerous = false,
    ): array {
        return self::describe($label, $description, $dangerous, true);
    }
}
