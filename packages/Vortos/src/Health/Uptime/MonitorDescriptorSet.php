<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use InvalidArgumentException;

/** A declared collection of {@see MonitorDescriptor}; duplicate keys are rejected eagerly. */
final class MonitorDescriptorSet
{
    /** @var array<string, MonitorDescriptor> */
    private array $descriptors = [];

    /** @param list<MonitorDescriptor> $descriptors */
    public function __construct(array $descriptors = [])
    {
        foreach ($descriptors as $descriptor) {
            $this->add($descriptor);
        }
    }

    public function add(MonitorDescriptor $descriptor): void
    {
        if (isset($this->descriptors[$descriptor->key])) {
            throw new InvalidArgumentException(sprintf('Duplicate monitor descriptor key "%s".', $descriptor->key));
        }

        $this->descriptors[$descriptor->key] = $descriptor;
    }

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    public function get(string $key): MonitorDescriptor
    {
        return $this->descriptors[$key] ?? throw new InvalidArgumentException(sprintf('Unknown monitor descriptor "%s".', $key));
    }

    /** @return list<MonitorDescriptor> */
    public function all(): array
    {
        return array_values($this->descriptors);
    }
}
