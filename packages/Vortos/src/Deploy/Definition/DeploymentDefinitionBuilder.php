<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

use Vortos\Deploy\Runtime\FileSecret;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Runtime\AppHealthcheck;
use Vortos\Deploy\Runtime\WorkerHealthcheck;
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
    private bool $autoPublishMigrations = false;
    private ?bool $backupToolchainExternal = null;
    private bool $pruneImages = true;
    private int $pruneImagesKeep = 2;
    private string $builderCacheMaxAge = '168h';
    private int $workerDrainDeadlineSeconds = 25;
    private string $edgeRouter = 'caddy';
    private string $canaryAnalyzer = 'null';
    private WorkerTopology $workerTopology = WorkerTopology::RideColor;

    // Runtime service shape (B16): how the blue/green app + worker containers actually run. Defaults
    // match the shipped FrankenPHP stub; apps override via the setters below in config/deploy.php.
    /** @var list<string> */
    private array $appCommand = RuntimeServiceSpec::DEFAULT_COMMAND;
    /** @var list<string> */
    private array $workerCommand = RuntimeServiceSpec::DEFAULT_WORKER_COMMAND;
    private int $containerPort = RuntimeServiceSpec::DEFAULT_CONTAINER_PORT;
    /** @var list<string> */
    private array $envFiles = [RuntimeServiceSpec::DEFAULT_ENV_FILE];
    /** @var array<string, string> */
    private array $appEnvironment = ['SERVER_NAME' => ':8080'];
    /** @var list<FileSecret> */
    private array $fileSecrets = [];
    private ?WorkerHealthcheck $workerHealthcheck = null;

    private ?AppHealthcheck $appHealthcheck = null;

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

    /**
     * R8-1: when true, a live 'deploy' publishes any un-published module migration stubs before the
     * doctor gate. Off by default — the default posture is the fail-closed UnpublishedStubCheck.
     */
    public function autoPublishMigrations(bool $autoPublishMigrations): self
    {
        $clone = clone $this;
        $clone->autoPublishMigrations = $autoPublishMigrations;

        return $clone;
    }

    /**
     * R8-2: declare whether the backup toolchain (pg_dump/…) lives on a dedicated backup role image
     * rather than the lean deploy image. When set, this wins over VORTOS_BACKUP_TOOLCHAIN_EXTERNAL.
     */
    public function backupToolchainExternal(bool $external): self
    {
        $clone = clone $this;
        $clone->backupToolchainExternal = $external;

        return $clone;
    }

    /**
     * R8-4: reclaim superseded release images + build cache on the target after a successful cutover.
     * Keeps the active release and previous-for-rollback ($keep, min 2). Pass $enabled=false to disable.
     */
    public function pruneImages(bool $enabled = true, int $keep = 2, string $builderCacheMaxAge = '168h'): self
    {
        $clone = clone $this;
        $clone->pruneImages = $enabled;
        $clone->pruneImagesKeep = max(2, $keep);
        $clone->builderCacheMaxAge = $builderCacheMaxAge;

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

    /**
     * How workers are rolled during deploy (B20). Accepts a {@see WorkerTopology} or its string value
     * ('ride-color' | 'external-supervisor'). Defaults to ride-color: workers ride the compose color
     * and no supervisorctl RollWorkers phase is emitted.
     */
    public function workerTopology(WorkerTopology|string $topology): self
    {
        $resolved = $topology instanceof WorkerTopology
            ? $topology
            : (WorkerTopology::tryFrom($topology) ?? throw new \InvalidArgumentException(sprintf(
                'Unknown worker topology "%s". Valid: [%s].',
                $topology,
                implode(', ', array_map(static fn (WorkerTopology $t): string => $t->value, WorkerTopology::cases())),
            )));

        $clone = clone $this;
        $clone->workerTopology = $resolved;

        return $clone;
    }

    /** @param list<string> $command the HTTP server argv (must exist in the image) */
    public function appCommand(array $command): self
    {
        $clone = clone $this;
        $clone->appCommand = array_values($command);

        return $clone;
    }

    /** @param list<string> $command the worker argv (supervisord/consume) */
    public function workerCommand(array $command): self
    {
        $clone = clone $this;
        $clone->workerCommand = array_values($command);

        return $clone;
    }

    /** The internal port the app serves on (edge dials app-<color>:<port>; colors publish no host ports). */
    public function containerPort(int $port): self
    {
        $clone = clone $this;
        $clone->containerPort = $port;

        return $clone;
    }

    /** @param list<string> $envFiles absolute paths mounted into the color as env files */
    public function envFiles(array $envFiles): self
    {
        $clone = clone $this;
        $clone->envFiles = array_values($envFiles);

        return $clone;
    }

    /** @param array<string, string> $environment extra app-service environment (e.g. SERVER_NAME) */
    public function appEnvironment(array $environment): self
    {
        $clone = clone $this;
        $clone->appEnvironment = $environment;

        return $clone;
    }

    /**
     * Declare a file-shaped secret (G8): decrypted from the age store to a tmpfs host path by the
     * deploy one-shot and bind-mounted read-only into the color at $containerPath. Prefer env-content
     * secrets; use this only for genuinely file-shaped cases.
     */
    public function fileSecret(string $name, string $containerPath, ?string $hostPath = null, int $mode = 0400): self
    {
        $clone = clone $this;
        $clone->fileSecrets[] = new FileSecret(
            name: $name,
            containerPath: $containerPath,
            hostPath: $hostPath ?? ('/run/vortos-secrets/' . $name),
            mode: $mode,
        );

        return $clone;
    }

    /**
     * Override the worker service healthcheck (GAP-G). Optional — the framework default already
     * overrides the base image's inherited HTTP healthcheck with a supervisorctl check (supervisord
     * worker) or a disable (custom worker command). Use this to declare a bespoke worker liveness check.
     */
    public function workerHealthcheck(WorkerHealthcheck $healthcheck): self
    {
        $clone = clone $this;
        $clone->workerHealthcheck = $healthcheck;

        return $clone;
    }

    /**
     * Override the app service readiness healthcheck. Optional — the framework default is an HTTP probe
     * of the canonical /health/ready contract on the container port, which the worker gates on so its
     * consumer fan-out cannot race the app's readiness. Use {@see AppHealthcheck::disabled()} for a
     * custom, non-HTTP app (the worker then falls back to co-booting with the app).
     */
    public function appHealthcheck(AppHealthcheck $healthcheck): self
    {
        $clone = clone $this;
        $clone->appHealthcheck = $healthcheck;

        return $clone;
    }

    /**
     * The resolved runtime service shape. Consumed by the DI container to drive the cutover compose
     * generation ({@see \Vortos\Deploy\Compose\ComposeProjectFactory}). Env-agnostic: command/port
     * are image-level facts, so per-environment overrides don't change them.
     */
    public function getRuntimeServiceSpec(): RuntimeServiceSpec
    {
        return new RuntimeServiceSpec(
            command: $this->appCommand,
            containerPort: $this->containerPort,
            envFiles: $this->envFiles,
            workerCommand: $this->workerCommand,
            environment: $this->appEnvironment,
            fileSecrets: $this->fileSecrets,
            workerHealthcheck: $this->workerHealthcheck,
            appHealthcheck: $this->appHealthcheck,
        );
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
            autoPublishMigrations: $this->autoPublishMigrations,
            backupToolchainExternal: $this->backupToolchainExternal,
            pruneImages: $this->pruneImages,
            pruneImagesKeep: $this->pruneImagesKeep,
            builderCacheMaxAge: $this->builderCacheMaxAge,
            definitionHash: 'sha256:' . $hash,
            workerDrainDeadlineSeconds: $this->workerDrainDeadlineSeconds,
            edgeRouter: $this->edgeRouter,
            canaryAnalyzer: $this->canaryAnalyzer,
            runtimeService: $this->getRuntimeServiceSpec(),
            workerTopology: $this->workerTopology,
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
            'auto_publish_migrations' => $this->autoPublishMigrations,
            'backup_toolchain_external' => $this->backupToolchainExternal,
            'builder_cache_max_age' => $this->builderCacheMaxAge,
            'ci' => $this->ci,
            'prune_images' => $this->pruneImages,
            'prune_images_keep' => $this->pruneImagesKeep,
            'credential' => $this->credential,
            'host' => $this->host,
            'monitoring' => $this->monitoring,
            'notifiers' => $this->notifiers,
            'registry' => $this->registry,
            'secrets' => $this->secrets,
            'strategy' => $strategy->value,
            'worker_drain_deadline_seconds' => $this->workerDrainDeadlineSeconds,
            'worker_topology' => $this->workerTopology->value,
            'runtime_service' => $this->getRuntimeServiceSpec()->toArray(),
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
