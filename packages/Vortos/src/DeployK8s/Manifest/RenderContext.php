<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Manifest;

final readonly class RenderContext
{
    /**
     * @param array<string, string> $envVars Environment variable names to inject (name → secretKeyRef or value)
     * @param array<string, string> $labels  Extra labels to merge onto every resource
     * @param array<string, string> $secretRefs Secret names for envFrom.secretRef injection
     */
    public function __construct(
        public string $namespace = 'default',
        public string $appName = 'app',
        public string $imageReference = '',
        public int $replicas = 2,
        public int $port = 8080,
        public string $healthPath = '/ready',
        public string $livePath = '/live',
        public int $minReplicas = 2,
        public int $maxReplicas = 10,
        public int $cpuTargetPercent = 75,
        public string $serviceAccountName = 'deployer',
        public array $envVars = [],
        public array $labels = [],
        public array $secretRefs = [],
    ) {
        if ($namespace === '') {
            throw new \InvalidArgumentException('RenderContext namespace must not be empty.');
        }
        if ($appName === '') {
            throw new \InvalidArgumentException('RenderContext appName must not be empty.');
        }
    }
}
