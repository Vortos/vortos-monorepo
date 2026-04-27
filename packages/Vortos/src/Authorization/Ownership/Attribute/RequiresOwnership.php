<?php
declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Attribute;

/**
 * Requires the identity to own the resource.
 *
 * #[RequiresOwnership(DocumentOwnershipPolicy::class)]
 * public function deleteDocument(string $id): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresOwnership
{
    public function __construct(
        public readonly string $policy,
    ) {}
}
