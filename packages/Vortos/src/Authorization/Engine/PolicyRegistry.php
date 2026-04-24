<?php

declare(strict_types=1);

namespace Vortos\Authorization\Engine;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Exception\PolicyNotFoundException;

/**
 * Registry of all registered policies.
 *
 * Backed by a Symfony ServiceLocator populated at compile time
 * by PolicyRegistryPass. Policies are lazy-loaded — a policy class
 * is not instantiated until the first request that needs it.
 *
 * The registry is keyed by resource name — the first segment of the
 * permission string. 'athletes.update.own' → looks up key 'athletes'.
 */
final class PolicyRegistry implements PolicyRegistryInterface
{
    /**
     * @param ServiceLocator $policies Keyed by resource name → PolicyInterface service
     */
    public function __construct(private ServiceLocator $policies) {}

    /**
     * {@inheritdoc}
     */
    public function findForResource(string $resource): PolicyInterface
    {
        if (!$this->policies->has($resource)) {
            throw PolicyNotFoundException::forResource($resource);
        }

        return $this->policies->get($resource);
    }

    /**
     * {@inheritdoc}
     */
    public function hasForResource(string $resource): bool
    {
        return $this->policies->has($resource);
    }
}
