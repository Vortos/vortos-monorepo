<?php
declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Contract;

use Symfony\Component\HttpFoundation\Request;
use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Defines ownership check for a resource.
 *
 * Auto-discovered — just implement this interface.
 *
 * Example:
 *   class DocumentOwnershipPolicy implements OwnershipPolicyInterface
 *   {
 *       public function isOwner(UserIdentityInterface $identity, string $resourceId): bool
 *       {
 *           $doc = $this->documents->findById($resourceId);
 *           return $doc?->getAuthorId() === $identity->id();
 *       }
 *
 *       public function getResourceIdFrom(Request $request): string
 *       {
 *           return $request->attributes->get('id');
 *       }
 *   }
 */
interface OwnershipPolicyInterface
{
    public function isOwner(UserIdentityInterface $identity, string $resourceId): bool;
    public function getResourceIdFrom(Request $request): string;
}
