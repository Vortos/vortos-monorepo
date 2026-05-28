<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\Lifecycle\LifecycleConfiguration;
use Vortos\ObjectStore\Lifecycle\LifecyclePlan;

interface LifecycleManagerInterface
{
    public function current(): LifecycleConfiguration;

    public function planTemporaryUploadExpiry(): LifecyclePlan;

    public function planRemoveManagedRule(): LifecyclePlan;

    public function apply(LifecyclePlan $plan): LifecycleConfiguration;
}
