<?php

declare(strict_types=1);

namespace Vortos\Authorization\Attribute;

use Attribute;

/**
 * Marks a class as an authorization policy.
 *
 * Discovered by PolicyRegistryPass at compile time.
 * The policy is registered in PolicyRegistry keyed by resource name.
 *
 * ## Usage
 *
 *   #[AsPolicy(resource: 'athletes')]
 *   final class AthletePolicy implements PolicyInterface
 *   {
 *       public function supports(string $resource): bool
 *       {
 *           return $resource === 'athletes';
 *       }
 *
 *       public function can(
 *           UserIdentityInterface $identity,
 *           string $action,
 *           string $scope,
 *           mixed $resource = null,
 *       ): bool {
 *           // ... authorization logic
 *       }
 *   }
 *
 * ## Resource naming
 *
 * Use the same resource name as in your permission strings.
 * 'athletes' maps to all 'athletes.*.*' permissions.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsPolicy
{
    public function __construct(
        /**
         * The resource name this policy handles.
         * Must match the resource segment of permission strings.
         * e.g. 'athletes' handles 'athletes.update.own'
         */
        public readonly string $resource,
    ) {}
}
