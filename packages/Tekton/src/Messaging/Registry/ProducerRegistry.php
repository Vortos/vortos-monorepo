<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry;

use Fortizan\Tekton\Messaging\Definition\Producer\AbstractProducerDefinition;
use Fortizan\Tekton\Messaging\Registry\Exception\ProducerNotFoundException;

/**
 * Runtime registry of all registered producer definitions.
 *
 * Populated by ProducerRegistryCompilerPass at container compile time.
 * Read-only at runtime — never modified after the container is built.
 * Inject this wherever a producer definition needs to be looked up by name.
 */
final readonly class ProducerRegistry
{
    public function __construct(
        /** @var array<string, AbstractProducerDefinition> */
        public array $producers
    ) {}

    public function get(string $name): AbstractProducerDefinition
    {
        return $this->producers[$name] ?? throw ProducerNotFoundException::forName($name);
    }

    public function has(string $name): bool
    {
        return isset($this->producers[$name]);
    }

    public function all(): array
    {
        return $this->producers;
    }

    public function names(): array
    {
        return array_keys($this->producers);
    }
}
