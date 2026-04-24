<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

/**
 * Registry of all registered policies.
 *
 * Populated at compile time by PolicyRegistryPass — no runtime registration.
 * Policies are discovered via #[AsPolicy] attribute on policy classes.
 */
interface PolicyRegistryInterface
{
    /**
     * Find the policy that handles the given resource type.
     *
     * @throws \Vortos\Authorization\Exception\PolicyNotFoundException
     */
    public function findForResource(string $resource): PolicyInterface;

    /**
     * Check if any policy handles the given resource type.
     */
    public function hasForResource(string $resource): bool;
}
