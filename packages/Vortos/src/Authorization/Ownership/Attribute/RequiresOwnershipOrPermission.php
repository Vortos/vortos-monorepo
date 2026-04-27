<?php
declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Attribute;

/**
 * Requires ownership OR a specific override permission.
 * Admins with the override permission bypass ownership check.
 *
 * #[RequiresOwnershipOrPermission(
 *     policy: DocumentOwnershipPolicy::class,
 *     override: 'documents.delete.any'
 * )]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresOwnershipOrPermission
{
    public readonly string $override;

    public function __construct(
        public readonly string $policy,
        string|\BackedEnum $override,
    ) {
        $this->override = $override instanceof \BackedEnum ? $override->value : $override;
    }
}
