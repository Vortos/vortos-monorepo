<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

interface KubeApiInterface
{
    /** @throws KubeApiException */
    public function apply(KubeObject ...$objects): void;

    /**
     * @param array<string, string> $selector
     * @throws KubeApiConflictException on stale resourceVersion (CAS)
     * @throws KubeApiException
     */
    public function patchServiceSelector(string $service, string $namespace, array $selector, string $expectedResourceVersion): void;

    /** @throws KubeApiException */
    public function rolloutStatus(string $kind, string $name, string $namespace): RolloutStatus;

    /** @throws KubeApiException */
    public function createJob(KubeObject $job): void;

    /** @throws KubeApiException */
    public function awaitJob(string $name, string $namespace, int $timeoutSeconds): JobStatus;

    /** @throws KubeApiException */
    public function scale(string $kind, string $name, string $namespace, int $replicas): void;

    /** @throws KubeApiException */
    public function delete(string $kind, string $name, string $namespace): void;

    public function getService(string $name, string $namespace): ?ServiceInfo;
}
