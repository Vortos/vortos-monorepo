<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection;

final class TektonMessagingConfig
{
    private array $driverConfig = [];

    public function driver(): DriverConfig
    {
        return new DriverConfig($this->driverConfig);
    }

    public function toArray(): array
    {
        return ['driver' => $this->driverConfig];
    }
}
