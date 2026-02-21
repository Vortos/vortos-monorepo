<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry;

use Fortizan\Tekton\Messaging\Definition\Transport\AbstractTransportDefinition;
use Fortizan\Tekton\Messaging\Registry\Exception\TransportNotFoundException;

/**
 * Runtime registry of all registered transport definitions.
 *
 * Populated by TransportRegistryCompilerPass at container compile time.
 * Read-only at runtime — never modified after the container is built.
 * Inject this wherever a transport definition needs to be looked up by name.
 */
final readonly class TransportRegistry
{
    public function __construct(
        /** @var array<string, AbstractTransportDefinition> */
        public array $transports
    ) {}

    public function get(string $name): AbstractTransportDefinition
    {
        return $this->transports[$name] ?? throw TransportNotFoundException::forName($name);
    }

    public function has(string $name): bool
    {
        return isset($this->transports[$name]);
    }

    public function all(): array
    {
        return $this->transports;
    }

    public function names(): array
    {
        return array_keys($this->transports);
    }
}
