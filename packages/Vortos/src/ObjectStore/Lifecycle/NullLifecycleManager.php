<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Vortos\ObjectStore\Contract\LifecycleManagerInterface;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class NullLifecycleManager implements LifecycleManagerInterface
{
    public function current(): LifecycleConfiguration
    {
        throw $this->unsupported();
    }

    public function planTemporaryUploadExpiry(): LifecyclePlan
    {
        throw $this->unsupported();
    }

    public function planRemoveManagedRule(): LifecyclePlan
    {
        throw $this->unsupported();
    }

    public function apply(LifecyclePlan $plan): LifecycleConfiguration
    {
        throw $this->unsupported();
    }

    private function unsupported(): ObjectStoreConfigurationException
    {
        return new ObjectStoreConfigurationException('Object-store lifecycle management requires the S3-compatible driver.');
    }
}
