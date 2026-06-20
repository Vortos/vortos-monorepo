<?php

declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Contract;

/**
 * Resolves the owner id of a loaded resource object for a given type.
 *
 * The canonical, no-domain-coupling ownership mechanism: implement this in the
 * application/infrastructure layer for types you cannot (or do not want to)
 * annotate with #[Owner]. Auto-discovered — just implement the interface.
 *
 *   final class InvoiceOwnerResolver implements OwnerResolverInterface
 *   {
 *       public function resourceType(): string { return Invoice::class; }
 *       public function ownerId(object $resource): ?string
 *       {
 *           return $resource instanceof Invoice ? (string) $resource->customerId() : null;
 *       }
 *   }
 */
interface OwnerResolverInterface
{
    /**
     * Fully-qualified class (or interface) name this resolver handles. A resource is
     * matched when it is an instanceof this type.
     *
     * @return class-string
     */
    public function resourceType(): string;

    /**
     * @return string|null The owner id, or null when ownership cannot be determined.
     */
    public function ownerId(object $resource): ?string;
}
