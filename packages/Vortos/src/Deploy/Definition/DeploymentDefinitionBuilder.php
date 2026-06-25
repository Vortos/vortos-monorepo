<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Release\Manifest\Arch;

final class DeploymentDefinitionBuilder
{
    private string $host = 'ssh-compose';
    private string $registry = 'dockerhub';
    private string $ci = 'github';
    private string $secrets = 'env';
    private string $monitoring = 'grafana';
    /** @var list<string> */
    private array $notifiers = [];
    private string $credential = 'ssh-key';
    private string $strategyKey = 'blue-green';
    private string $archValue = 'linux/arm64';
    private bool $autoRollback = true;
    private int $workerDrainDeadlineSeconds = 25;
    private string $edgeRouter = 'caddy';
    private string $canaryAnalyzer = 'null';

    /** @var array<string, \Closure(self): self> */
    private array $envOverrides = [];

    public function host(string $host): self
    {
        $clone = clone $this;
        $clone->host = $host;

        return $clone;
    }

    public function registry(string $registry): self
    {
        $clone = clone $this;
        $clone->registry = $registry;

        return $clone;
    }

    public function ci(string $ci): self
    {
        $clone = clone $this;
        $clone->ci = $ci;

        return $clone;
    }

    public function secrets(string $secrets): self
    {
        $clone = clone $this;
        $clone->secrets = $secrets;

        return $clone;
    }

    public function monitoring(string $monitoring): self
    {
        $clone = clone $this;
        $clone->monitoring = $monitoring;

        return $clone;
    }

    /** @param list<string> $notifiers */
    public function notifiers(array $notifiers): self
    {
        $clone = clone $this;
        $clone->notifiers = array_values(array_unique($notifiers));

        return $clone;
    }

    public function credential(string $credential): self
    {
        $clone = clone $this;
        $clone->credential = $credential;

        return $clone;
    }

    public function strategy(string $strategyKey): self
    {
        $clone = clone $this;
        $clone->strategyKey = $strategyKey;

        return $clone;
    }

    public function arch(string $archValue): self
    {
        $clone = clone $this;
        $clone->archValue = $archValue;

        return $clone;
    }

    public function autoRollback(bool $autoRollback): self
    {
        $clone = clone $this;
        $clone->autoRollback = $autoRollback;

        return $clone;
    }

    public function workerDrainDeadlineSeconds(int $seconds): self
    {
        $clone = clone $this;
        $clone->workerDrainDeadlineSeconds = $seconds;

        return $clone;
    }

    public function edgeRouter(string $edgeRouter): self
    {
        $clone = clone $this;
        $clone->edgeRouter = $edgeRouter;

        return $clone;
    }

    public function canaryAnalyzer(string $canaryAnalyzer): self
    {
        $clone = clone $this;
        $clone->canaryAnalyzer = $canaryAnalyzer;

        return $clone;
    }

    /** @param \Closure(self): self $override */
    public function forEnvironment(string $env, \Closure $override): self
    {
        $clone = clone $this;
        $clone->envOverrides[$env] = $override;

        return $clone;
    }

    public function build(): DeploymentDefinition
    {
        $strategy = DeployStrategy::tryFrom($this->strategyKey);
        if ($strategy === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown strategy "%s". Valid: [%s].',
                $this->strategyKey,
                implode(', ', array_map(static fn (DeployStrategy $s): string => $s->value, DeployStrategy::cases())),
            ));
        }

        $arch = Arch::tryFrom($this->archValue);
        if ($arch === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown arch "%s". Valid: [%s].',
                $this->archValue,
                implode(', ', array_map(static fn (Arch $a): string => $a->value, Arch::cases())),
            ));
        }

        $this->validateRequiredField('host', $this->host);
        $this->validateRequiredField('registry', $this->registry);
        $this->validateRequiredField('ci', $this->ci);
        $this->validateRequiredField('secrets', $this->secrets);
        $this->validateRequiredField('credential', $this->credential);

        $data = $this->toArray($strategy, $arch);
        $hash = hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));

        return new DeploymentDefinition(
            host: $this->host,
            registry: $this->registry,
            ci: $this->ci,
            secrets: $this->secrets,
            monitoring: $this->monitoring,
            notifiers: $this->notifiers,
            credential: $this->credential,
            strategy: $strategy,
            arch: $arch,
            autoRollback: $this->autoRollback,
            definitionHash: 'sha256:' . $hash,
            workerDrainDeadlineSeconds: $this->workerDrainDeadlineSeconds,
            edgeRouter: $this->edgeRouter,
            canaryAnalyzer: $this->canaryAnalyzer,
        );
    }

    /**
     * Apply per-env overrides and build the effective definition for a specific environment.
     */
    public function buildForEnvironment(string $env): DeploymentDefinition
    {
        $builder = $this;
        if (isset($this->envOverrides[$env])) {
            $builder = ($this->envOverrides[$env])($builder);
        }

        return $builder->build();
    }

    /** @return array<string, \Closure(self): self> */
    public function getEnvironmentOverrides(): array
    {
        return $this->envOverrides;
    }

    /** @return array<string, mixed> */
    private function toArray(DeployStrategy $strategy, Arch $arch): array
    {
        $data = [
            'arch' => $arch->value,
            'auto_rollback' => $this->autoRollback,
            'ci' => $this->ci,
            'credential' => $this->credential,
            'host' => $this->host,
            'monitoring' => $this->monitoring,
            'notifiers' => $this->notifiers,
            'registry' => $this->registry,
            'secrets' => $this->secrets,
            'strategy' => $strategy->value,
            'worker_drain_deadline_seconds' => $this->workerDrainDeadlineSeconds,
        ];

        ksort($data);

        return $data;
    }

    private function validateRequiredField(string $name, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf(
                'Deployment definition field "%s" must not be empty.',
                $name,
            ));
        }
    }
}
