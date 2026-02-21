<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry;

use Fortizan\Tekton\Messaging\Definition\Consumer\AbstractConsumerDefinition;
use Fortizan\Tekton\Messaging\Registry\Exception\ConsumerNotFoundException;

/**
 * Runtime registry of all registered consumer definitions.
 *
 * Populated by ConsumerRegistryCompilerPass at container compile time.
 * Read-only at runtime — never modified after the container is built.
 * Inject this wherever a consumer definition needs to be looked up by name.
 */
final readonly class ConsumerRegistry
{
    public function __construct(
        /** @var array<string, AbstractConsumerDefinition> */
        public array $consumers
    ) {}

    public function get(string $name): AbstractConsumerDefinition
    {
        return $this->consumers[$name] ?? throw ConsumerNotFoundException::forName($name);
    }

    public function has(string $name): bool
    {
        return isset($this->consumers[$name]);
    }

    public function all(): array
    {
        return $this->consumers;
    }

    public function names(): array
    {
        return array_keys($this->consumers);
    }
}
