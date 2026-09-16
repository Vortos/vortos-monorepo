<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\Lifecycle\LifecycleConfiguration;
use Vortos\ObjectStore\Lifecycle\LifecyclePlan;

interface LifecycleManagerInterface
{
    public function current(): LifecycleConfiguration;

    /**
     * Brings every managed rule to its declared shape: creates missing ones, rewrites drifted ones,
     * removes managed rules no longer declared. Rules outside the managed namespace are kept as-is.
     */
    public function planManagedRules(): LifecyclePlan;

    /** Removes every rule in the managed namespace, keeping all others. */
    public function planRemoveManagedRules(): LifecyclePlan;

    public function apply(LifecyclePlan $plan): LifecycleConfiguration;
}
