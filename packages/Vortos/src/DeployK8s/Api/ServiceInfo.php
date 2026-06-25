<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

final readonly class ServiceInfo
{
    /** @param array<string, string> $selector */
    public function __construct(
        public string $name,
        public string $namespace,
        public array $selector,
        public string $resourceVersion,
        public int $port = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'selector' => $this->selector,
            'resource_version' => $this->resourceVersion,
            'port' => $this->port,
        ];
    }
}
