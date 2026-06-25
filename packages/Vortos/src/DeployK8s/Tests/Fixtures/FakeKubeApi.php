<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Fixtures;

use Vortos\DeployK8s\Api\JobStatus;
use Vortos\DeployK8s\Api\KubeApiConflictException;
use Vortos\DeployK8s\Api\KubeApiException;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\DeployK8s\Api\KubeObject;
use Vortos\DeployK8s\Api\RolloutStatus;
use Vortos\DeployK8s\Api\ServiceInfo;

final class FakeKubeApi implements KubeApiInterface
{
    /** @var list<array{op: string, args: array<string, mixed>}> */
    public array $ops = [];

    /** @var array<string, KubeObject> Keyed by "kind/namespace/name" */
    private array $objects = [];

    /** @var array<string, ServiceInfo> Keyed by "namespace/name" */
    private array $services = [];

    /** @var array<string, int> Keyed by "kind/namespace/name" → replica count */
    private array $scales = [];

    private ?RolloutStatus $nextRolloutStatus = null;
    private ?JobStatus $nextJobStatus = null;
    private bool $conflictOnNextPatch = false;
    private bool $failOnNextApply = false;
    private bool $failOnNextJob = false;

    public function setNextRolloutStatus(RolloutStatus $status): void
    {
        $this->nextRolloutStatus = $status;
    }

    public function setNextJobStatus(JobStatus $status): void
    {
        $this->nextJobStatus = $status;
    }

    public function setConflictOnNextPatch(bool $conflict = true): void
    {
        $this->conflictOnNextPatch = $conflict;
    }

    public function setFailOnNextApply(bool $fail = true): void
    {
        $this->failOnNextApply = $fail;
    }

    public function setFailOnNextJob(bool $fail = true): void
    {
        $this->failOnNextJob = $fail;
    }

    /** @param array<string, string> $selector */
    public function seedService(string $name, string $namespace, array $selector, string $resourceVersion = '1', int $port = 8080): void
    {
        $key = $namespace . '/' . $name;
        $this->services[$key] = new ServiceInfo($name, $namespace, $selector, $resourceVersion, $port);
    }

    public function apply(KubeObject ...$objects): void
    {
        $this->ops[] = ['op' => 'apply', 'args' => ['objects' => array_map(fn (KubeObject $o) => $o->toArray(), $objects)]];

        if ($this->failOnNextApply) {
            $this->failOnNextApply = false;
            throw KubeApiException::applyFailed($objects[0]->kind, $objects[0]->name, 'fake apply failure');
        }

        foreach ($objects as $object) {
            $key = $object->kind . '/' . $object->namespace . '/' . $object->name;
            $this->objects[$key] = $object;

            if ($object->kind === 'Deployment') {
                $replicas = $object->spec['spec']['replicas'] ?? 1;
                $this->scales[$key] = (int) $replicas;
            }
        }
    }

    public function patchServiceSelector(string $service, string $namespace, array $selector, string $expectedResourceVersion): void
    {
        $this->ops[] = ['op' => 'patchServiceSelector', 'args' => [
            'service' => $service,
            'namespace' => $namespace,
            'selector' => $selector,
            'expectedResourceVersion' => $expectedResourceVersion,
        ]];

        if ($this->conflictOnNextPatch) {
            $this->conflictOnNextPatch = false;
            throw KubeApiConflictException::staleResourceVersion($namespace . '/' . $service, $expectedResourceVersion);
        }

        $key = $namespace . '/' . $service;
        $existing = $this->services[$key] ?? null;
        $newVersion = $existing !== null ? (string) ((int) $existing->resourceVersion + 1) : '1';
        $port = $existing?->port ?? 8080;

        $this->services[$key] = new ServiceInfo($service, $namespace, $selector, $newVersion, $port);
    }

    public function rolloutStatus(string $kind, string $name, string $namespace): RolloutStatus
    {
        $this->ops[] = ['op' => 'rolloutStatus', 'args' => [
            'kind' => $kind,
            'name' => $name,
            'namespace' => $namespace,
        ]];

        if ($this->nextRolloutStatus !== null) {
            $status = $this->nextRolloutStatus;
            $this->nextRolloutStatus = null;
            return $status;
        }

        $key = $kind . '/' . $namespace . '/' . $name;
        $replicas = $this->scales[$key] ?? 1;

        return new RolloutStatus(
            ready: true,
            readyReplicas: $replicas,
            desiredReplicas: $replicas,
            updatedReplicas: $replicas,
            imageDigest: 'sha256:' . str_repeat('a', 64),
        );
    }

    public function createJob(KubeObject $job): void
    {
        $this->ops[] = ['op' => 'createJob', 'args' => ['job' => $job->toArray()]];

        if ($this->failOnNextJob) {
            $this->failOnNextJob = false;
            throw KubeApiException::jobFailed($job->name, 'fake job creation failure');
        }

        $key = $job->kind . '/' . $job->namespace . '/' . $job->name;
        $this->objects[$key] = $job;
    }

    public function awaitJob(string $name, string $namespace, int $timeoutSeconds): JobStatus
    {
        $this->ops[] = ['op' => 'awaitJob', 'args' => [
            'name' => $name,
            'namespace' => $namespace,
            'timeoutSeconds' => $timeoutSeconds,
        ]];

        if ($this->nextJobStatus !== null) {
            $status = $this->nextJobStatus;
            $this->nextJobStatus = null;
            return $status;
        }

        return new JobStatus(completed: true, failed: false);
    }

    public function scale(string $kind, string $name, string $namespace, int $replicas): void
    {
        $this->ops[] = ['op' => 'scale', 'args' => [
            'kind' => $kind,
            'name' => $name,
            'namespace' => $namespace,
            'replicas' => $replicas,
        ]];

        $key = $kind . '/' . $namespace . '/' . $name;
        $this->scales[$key] = $replicas;
    }

    public function delete(string $kind, string $name, string $namespace): void
    {
        $this->ops[] = ['op' => 'delete', 'args' => [
            'kind' => $kind,
            'name' => $name,
            'namespace' => $namespace,
        ]];

        $key = $kind . '/' . $namespace . '/' . $name;
        unset($this->objects[$key], $this->scales[$key]);
    }

    public function getService(string $name, string $namespace): ?ServiceInfo
    {
        $this->ops[] = ['op' => 'getService', 'args' => [
            'name' => $name,
            'namespace' => $namespace,
        ]];

        return $this->services[$namespace . '/' . $name] ?? null;
    }

    public function opCount(): int
    {
        return \count($this->ops);
    }

    /** @return list<string> */
    public function opNames(): array
    {
        return array_map(fn (array $op) => $op['op'], $this->ops);
    }

    public function hasObject(string $kind, string $name, string $namespace): bool
    {
        return isset($this->objects[$kind . '/' . $namespace . '/' . $name]);
    }

    public function getScale(string $kind, string $name, string $namespace): ?int
    {
        return $this->scales[$kind . '/' . $namespace . '/' . $name] ?? null;
    }
}
