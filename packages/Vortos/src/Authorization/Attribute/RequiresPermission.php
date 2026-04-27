<?php
declare(strict_types=1);

namespace Vortos\Authorization\Attribute;

use Vortos\Authorization\Scope\Contract\ScopeMode;

/**
 * Declares the permission required to access a controller or handler.
 *
 * Permission format: resource.action.scope
 *
 * Basic usage:
 *   #[RequiresPermission('athletes.update.own')]
 *
 * Scoped usage — checks permission within org/team/project scope:
 *   #[RequiresPermission('documents.edit', scope: 'org')]
 *   #[RequiresPermission('documents.edit', scope: ['org', 'team'])]
 *   #[RequiresPermission('documents.edit', scope: ['org', 'team'], scopeMode: ScopeMode::Any)]
 *
 * String or BackedEnum:
 *   #[RequiresPermission(Permission::DocumentsEdit)]
 *   #[RequiresPermission(Permission::DocumentsEdit, scope: 'org')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresPermission
{
    public readonly string $permission;

    public function __construct(
        string|\BackedEnum $permission,
        public readonly ?string $resourceParam = null,
        public readonly string|array|null $scope = null,
        public readonly ScopeMode $scopeMode = ScopeMode::All,
    ) {
        $this->permission = $permission instanceof \BackedEnum ? $permission->value : $permission;
    }
}
