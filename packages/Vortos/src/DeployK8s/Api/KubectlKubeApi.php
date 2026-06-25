<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

use Vortos\Deploy\Execution\CommandRunnerInterface;

final class KubectlKubeApi implements KubeApiInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly string $kubeconfig = '',
        private readonly string $context = '',
    ) {}

    public function apply(KubeObject ...$objects): void
    {
        foreach ($objects as $object) {
            $json = $object->toJson();
            $argv = $this->baseArgv();
            $argv[] = 'apply';
            $argv[] = '--server-side';
            $argv[] = '-f';
            $argv[] = '-';

            $result = $this->runner->run($argv, stdin: $json, timeout: 30.0);
            if (!$result->isSuccess()) {
                throw KubeApiException::applyFailed($object->kind, $object->name, $result->stderr);
            }
        }
    }

    public function patchServiceSelector(string $service, string $namespace, array $selector, string $expectedResourceVersion): void
    {
        $svcInfo = $this->getService($service, $namespace);
        if ($svcInfo !== null && $svcInfo->resourceVersion !== $expectedResourceVersion) {
            throw KubeApiConflictException::staleResourceVersion($namespace . '/' . $service, $expectedResourceVersion);
        }

        $patch = json_encode(['spec' => ['selector' => $selector]], \JSON_THROW_ON_ERROR);

        $argv = $this->baseArgv();
        $argv[] = 'patch';
        $argv[] = 'service';
        $argv[] = $service;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '--type';
        $argv[] = 'merge';
        $argv[] = '-p';
        $argv[] = $patch;

        $result = $this->runner->run($argv, timeout: 15.0);
        if (!$result->isSuccess()) {
            if (str_contains($result->stderr, 'Conflict') || str_contains($result->stderr, 'the object has been modified')) {
                throw KubeApiConflictException::staleResourceVersion($namespace . '/' . $service, $expectedResourceVersion);
            }
            throw new KubeApiException(sprintf('Failed to patch service %s/%s selector: %s', $namespace, $service, $result->stderr));
        }
    }

    public function rolloutStatus(string $kind, string $name, string $namespace): RolloutStatus
    {
        $argv = $this->baseArgv();
        $argv[] = 'get';
        $argv[] = strtolower($kind);
        $argv[] = $name;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '-o';
        $argv[] = 'json';

        $result = $this->runner->run($argv, timeout: 15.0);
        $result->throwOnFailure('rollout status');

        $data = json_decode($result->stdout, true, 512, \JSON_THROW_ON_ERROR);

        $status = $data['status'] ?? [];
        $spec = $data['spec'] ?? [];

        $desired = (int) ($spec['replicas'] ?? 1);
        $ready = (int) ($status['readyReplicas'] ?? 0);
        $updated = (int) ($status['updatedReplicas'] ?? 0);

        $containers = $spec['template']['spec']['containers'] ?? [];
        $imageDigest = '';
        if (isset($containers[0]['image']) && str_contains($containers[0]['image'], '@sha256:')) {
            $parts = explode('@', $containers[0]['image']);
            $imageDigest = end($parts);
        }

        $conditions = $status['conditions'] ?? [];
        $available = false;
        foreach ($conditions as $c) {
            if (($c['type'] ?? '') === 'Available' && ($c['status'] ?? '') === 'True') {
                $available = true;
            }
        }

        return new RolloutStatus(
            ready: $available && $ready >= $desired,
            readyReplicas: $ready,
            desiredReplicas: $desired,
            updatedReplicas: $updated,
            imageDigest: $imageDigest,
        );
    }

    public function createJob(KubeObject $job): void
    {
        $json = $job->toJson();
        $argv = $this->baseArgv();
        $argv[] = 'apply';
        $argv[] = '--server-side';
        $argv[] = '-f';
        $argv[] = '-';

        $result = $this->runner->run($argv, stdin: $json, timeout: 30.0);
        if (!$result->isSuccess()) {
            throw KubeApiException::jobFailed($job->name, $result->stderr);
        }
    }

    public function awaitJob(string $name, string $namespace, int $timeoutSeconds): JobStatus
    {
        $argv = $this->baseArgv();
        $argv[] = 'wait';
        $argv[] = '--for=condition=complete';
        $argv[] = 'job/' . $name;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '--timeout';
        $argv[] = $timeoutSeconds . 's';

        $result = $this->runner->run($argv, timeout: (float) ($timeoutSeconds + 10));

        if ($result->isSuccess()) {
            return new JobStatus(completed: true, failed: false);
        }

        $argv2 = $this->baseArgv();
        $argv2[] = 'get';
        $argv2[] = 'job/' . $name;
        $argv2[] = '--namespace';
        $argv2[] = $namespace;
        $argv2[] = '-o';
        $argv2[] = 'json';

        $check = $this->runner->run($argv2, timeout: 10.0);
        if ($check->isSuccess()) {
            $data = json_decode($check->stdout, true, 512, \JSON_THROW_ON_ERROR);
            $conditions = $data['status']['conditions'] ?? [];
            foreach ($conditions as $c) {
                if (($c['type'] ?? '') === 'Failed' && ($c['status'] ?? '') === 'True') {
                    return new JobStatus(completed: false, failed: true, message: $c['reason'] ?? 'Job failed');
                }
            }
        }

        return new JobStatus(completed: false, failed: true, message: 'Job timed out after ' . $timeoutSeconds . 's');
    }

    public function scale(string $kind, string $name, string $namespace, int $replicas): void
    {
        $argv = $this->baseArgv();
        $argv[] = 'scale';
        $argv[] = strtolower($kind) . '/' . $name;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '--replicas';
        $argv[] = (string) $replicas;

        $result = $this->runner->run($argv, timeout: 15.0);
        if (!$result->isSuccess()) {
            throw KubeApiException::scaleFailed($kind, $name, $result->stderr);
        }
    }

    public function delete(string $kind, string $name, string $namespace): void
    {
        $argv = $this->baseArgv();
        $argv[] = 'delete';
        $argv[] = strtolower($kind);
        $argv[] = $name;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '--ignore-not-found';

        $result = $this->runner->run($argv, timeout: 30.0);
        $result->throwOnFailure('delete');
    }

    public function getService(string $name, string $namespace): ?ServiceInfo
    {
        $argv = $this->baseArgv();
        $argv[] = 'get';
        $argv[] = 'service';
        $argv[] = $name;
        $argv[] = '--namespace';
        $argv[] = $namespace;
        $argv[] = '-o';
        $argv[] = 'json';

        $result = $this->runner->run($argv, timeout: 10.0);
        if (!$result->isSuccess()) {
            return null;
        }

        $data = json_decode($result->stdout, true, 512, \JSON_THROW_ON_ERROR);
        $selector = $data['spec']['selector'] ?? [];
        $resourceVersion = (string) ($data['metadata']['resourceVersion'] ?? '0');

        $ports = $data['spec']['ports'] ?? [];
        $port = isset($ports[0]['port']) ? (int) $ports[0]['port'] : 0;

        return new ServiceInfo($name, $namespace, $selector, $resourceVersion, $port);
    }

    /** @return list<string> */
    private function baseArgv(): array
    {
        $argv = ['kubectl'];

        if ($this->kubeconfig !== '') {
            $argv[] = '--kubeconfig';
            $argv[] = $this->kubeconfig;
        }

        if ($this->context !== '') {
            $argv[] = '--context';
            $argv[] = $this->context;
        }

        return $argv;
    }
}
