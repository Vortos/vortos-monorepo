<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

final readonly class KubeObject
{
    /** @param array<string, mixed> $spec */
    public function __construct(
        public string $kind,
        public string $name,
        public string $namespace,
        public array $spec,
    ) {
        if ($kind === '') {
            throw new \InvalidArgumentException('KubeObject kind must not be empty.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('KubeObject name must not be empty.');
        }
        if ($namespace === '') {
            throw new \InvalidArgumentException('KubeObject namespace must not be empty.');
        }
    }

    public function toJson(): string
    {
        return json_encode($this->spec, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'namespace' => $this->namespace,
            'spec' => $this->spec,
        ];
    }
}
