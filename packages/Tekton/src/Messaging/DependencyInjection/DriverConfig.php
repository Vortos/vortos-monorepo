<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection;

final class DriverConfig
{
    public function __construct(private array &$config) {}

    public function producer(string $class): static
    {
        $this->config['producer'] = $class;
        return $this;
    }

    public function consumer(string $class): static
    {
        $this->config['consumer'] = $class;
        return $this;
    }
}
