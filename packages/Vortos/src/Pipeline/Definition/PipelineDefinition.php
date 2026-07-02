<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\ServiceContainer;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Release\Manifest\Arch;

final readonly class PipelineDefinition
{
    public bool $oidc;

    /**
     * @param list<string>           $phpExtensions
     * @param list<string>           $environments
     * @param list<SplitPackage>     $splitPackageOverrides
     * @param list<ServiceContainer> $testServiceContainers CI sidecars for the test job (db/redis/kafka)
     * @param list<array{name: string, run: string}> $testSteps extra shell steps injected into the test job
     *                                                          (migrations, contract checks, …), after deps install
     */
    public function __construct(
        public string $emitter = 'github',
        public string $phpVersion = '8.5',
        public ?string $nodeVersion = null,
        public array $phpExtensions = ['redis'],
        public array $environments = ['production'],
        public bool $benchmark = false,
        public bool $uiBuild = false,
        public ?string $uiBuildPath = null,
        public array $splitPackageOverrides = [],
        public int $defaultTimeoutMinutes = 30,
        public Arch $targetArch = Arch::Arm64,
        public ?string $imageRepository = null,
        public BuildMode $buildMode = BuildMode::Native,
        public ?string $nativeRunnerLabel = null,
        ?bool $oidc = null,
        public ?string $baseImageDigest = null,
        public bool $emitSbom = true,
        public string $dockerfilePath = 'Dockerfile',
        public bool $emitScanGate = false,
        public bool $emitSign = false,
        public string $registryProvider = 'ghcr',
        // ── Workflow file targeting (upstream P1-4) ──
        public string $workflowFilename = 'ci.yml',
        public ?string $workflowName = null,
        // ── Test / static-analysis stage configuration (upstream P1-3) ──
        public string $testCommand = './vendor/bin/phpunit --testdox',
        public string $analyseCommand = './vendor/bin/phpstan analyse',
        public array $testServiceContainers = [],
        public array $testSteps = [],
    ) {
        if ($emitter === '') {
            throw new \InvalidArgumentException('Pipeline emitter must be non-empty.');
        }

        if ($registryProvider === '') {
            throw new \InvalidArgumentException('Registry provider must be non-empty.');
        }

        if ($workflowFilename === '' || !str_ends_with($workflowFilename, '.yml') || str_contains($workflowFilename, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow filename must be a bare *.yml name (no path), got "%s".',
                $workflowFilename,
            ));
        }

        if ($testCommand === '' || $analyseCommand === '') {
            throw new \InvalidArgumentException('Test and analyse commands must be non-empty.');
        }

        if ($defaultTimeoutMinutes < 1) {
            throw new \InvalidArgumentException('Default timeout must be at least 1 minute.');
        }

        if ($imageRepository !== null && preg_match('#^[a-z0-9]([a-z0-9._/-]|:[0-9])*$#', $imageRepository) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Image repository must be a valid registry reference (host/path), got "%s".',
                $imageRepository,
            ));
        }

        if ($baseImageDigest !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $baseImageDigest) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Base image digest must match sha256:<64 hex>, got "%s".',
                $baseImageDigest,
            ));
        }

        // A native (single-arch, non-emulated) build job runs on a self-provided runner, so its
        // `runs-on` label cannot be guessed by the framework — GitHub's own label is arch- and
        // version-specific (e.g. "ubuntu-24.04-arm"), and a wrong guess silently produces a
        // workflow that no runner ever picks up. Require the app to declare it explicitly.
        if ($imageRepository !== null && $buildMode === BuildMode::Native && ($nativeRunnerLabel ?? '') === '') {
            throw new \InvalidArgumentException(
                'A native build stage requires an explicit runner label. Set "native_runner_label" '
                . 'in config/pipeline.php or the PIPELINE_NATIVE_RUNNER_LABEL env var '
                . '(GitHub-hosted ARM64: "ubuntu-24.04-arm").',
            );
        }

        $this->oidc = $oidc ?? ($imageRepository !== null);
    }

    public function hasBuildStage(): bool
    {
        return $this->imageRepository !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'benchmark' => $this->benchmark,
            'build_mode' => $this->buildMode->value,
            'default_timeout_minutes' => $this->defaultTimeoutMinutes,
            'dockerfile_path' => $this->dockerfilePath,
            'emit_sbom' => $this->emitSbom,
            'emit_scan_gate' => $this->emitScanGate,
            'emit_sign' => $this->emitSign,
            'emitter' => $this->emitter,
            'environments' => $this->environments,
            'oidc' => $this->oidc,
            'php_extensions' => $this->phpExtensions,
            'php_version' => $this->phpVersion,
            'registry_provider' => $this->registryProvider,
            'target_arch' => $this->targetArch->value,
            'ui_build' => $this->uiBuild,
        ];

        if ($this->imageRepository !== null) {
            $data['image_repository'] = $this->imageRepository;
        }

        if ($this->nativeRunnerLabel !== null) {
            $data['native_runner_label'] = $this->nativeRunnerLabel;
        }

        if ($this->nodeVersion !== null) {
            $data['node_version'] = $this->nodeVersion;
        }

        if ($this->uiBuildPath !== null) {
            $data['ui_build_path'] = $this->uiBuildPath;
        }

        if ($this->splitPackageOverrides !== []) {
            $data['split_packages'] = array_map(
                static fn (SplitPackage $p): array => $p->toArray(),
                $this->splitPackageOverrides,
            );
        }

        if ($this->baseImageDigest !== null) {
            $data['base_image_digest'] = $this->baseImageDigest;
        }

        ksort($data);

        return $data;
    }
}
