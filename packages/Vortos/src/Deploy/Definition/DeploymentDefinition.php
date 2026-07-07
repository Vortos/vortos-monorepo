<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Foundation\Deploy\DeployPosture;
use Vortos\Release\Manifest\Arch;

final readonly class DeploymentDefinition
{
    public RuntimeServiceSpec $runtimeService;

    /**
     * @param list<string> $notifiers
     */
    public function __construct(
        public string $host,
        public string $registry,
        public string $ci,
        public string $secrets,
        public string $monitoring,
        public array $notifiers,
        public string $credential,
        public DeployStrategy $strategy,
        public Arch $arch,
        public bool $autoRollback,
        public string $definitionHash,
        public int $workerDrainDeadlineSeconds = 25,
        public bool $autoPublishMigrations = false,
        /** R8-2: null → defer to VORTOS_BACKUP_TOOLCHAIN_EXTERNAL; true/false → config wins. */
        public ?bool $backupToolchainExternal = null,
        /** R8-4: reclaim superseded release images + build cache after a successful cutover. */
        public bool $pruneImages = true,
        public int $pruneImagesKeep = 2,
        public string $builderCacheMaxAge = '168h',
        public string $edgeRouter = 'caddy',
        public string $canaryAnalyzer = 'null',
        ?RuntimeServiceSpec $runtimeService = null,
        public WorkerTopology $workerTopology = WorkerTopology::RideColor,
    ) {
        $this->runtimeService = $runtimeService ?? new RuntimeServiceSpec();
    }

    public static function create(): DeploymentDefinitionBuilder
    {
        return new DeploymentDefinitionBuilder();
    }

    /**
     * The typed deploy posture for the built-in credentials (ssh-key / ssh-ca-oidc / pull-agent), or
     * null when {@see $credential} is a custom provider registered outside the built-in set. Threaded
     * into the pipeline so the emitted CI deploy job's OIDC posture derives from the real credential,
     * never from whether an image repository happens to be set.
     */
    public function posture(): ?DeployPosture
    {
        return DeployPosture::tryFromCredential($this->credential);
    }

    /**
     * @param list<string>                                        $notifiers
     * @param array<string, \Closure(DeploymentDefinitionBuilder): DeploymentDefinitionBuilder> $envOverrides
     */
    public static function build(
        string $host = 'ssh-compose',
        string $registry = 'dockerhub',
        string $ci = 'github',
        string $secrets = 'env',
        string $monitoring = 'grafana',
        array $notifiers = [],
        string $credential = 'ssh-key',
        DeployStrategy $strategy = DeployStrategy::BlueGreen,
        Arch $arch = Arch::Arm64,
        bool $autoRollback = true,
        int $workerDrainDeadlineSeconds = 25,
        array $envOverrides = [],
        string $edgeRouter = 'caddy',
        string $canaryAnalyzer = 'null',
        WorkerTopology $workerTopology = WorkerTopology::RideColor,
    ): self {
        $builder = self::create()
            ->host($host)
            ->registry($registry)
            ->ci($ci)
            ->secrets($secrets)
            ->monitoring($monitoring)
            ->notifiers($notifiers)
            ->credential($credential)
            ->strategy($strategy->value)
            ->arch($arch->value)
            ->autoRollback($autoRollback)
            ->workerDrainDeadlineSeconds($workerDrainDeadlineSeconds)
            ->edgeRouter($edgeRouter)
            ->canaryAnalyzer($canaryAnalyzer)
            ->workerTopology($workerTopology);

        foreach ($envOverrides as $env => $override) {
            $builder = $builder->forEnvironment($env, $override);
        }

        return $builder->build();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'arch' => $this->arch->value,
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
            'strategy' => $this->strategy->value,
            'worker_drain_deadline_seconds' => $this->workerDrainDeadlineSeconds,
            'worker_topology' => $this->workerTopology->value,
            'runtime_service' => $this->runtimeService->toArray(),
        ];

        ksort($data);

        return $data;
    }
}
