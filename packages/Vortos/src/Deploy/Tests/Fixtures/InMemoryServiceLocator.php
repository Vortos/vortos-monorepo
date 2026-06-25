<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Psr\Container\ContainerInterface;

final class InMemoryServiceLocator implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(
        private readonly array $services,
    ) {}

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service '{$id}' not found.");
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /** @return array<string, string> */
    public function getProvidedServices(): array
    {
        return array_map(static fn (object $s): string => $s::class, $this->services);
    }
}
