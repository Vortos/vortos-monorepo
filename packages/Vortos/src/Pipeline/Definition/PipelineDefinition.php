<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Release\Manifest\Arch;

final readonly class PipelineDefinition
{
    public bool $oidc;

    /**
     * @param list<string>       $phpExtensions
     * @param list<string>       $environments
     * @param list<SplitPackage> $splitPackageOverrides
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
        public string $nativeRunnerLabel = 'ubuntu-24.04-arm64',
        ?bool $oidc = null,
        public ?string $baseImageDigest = null,
        public bool $emitSbom = true,
        public string $dockerfilePath = 'Dockerfile',
        public bool $emitScanGate = false,
        public bool $emitSign = false,
    ) {
        if ($emitter === '') {
            throw new \InvalidArgumentException('Pipeline emitter must be non-empty.');
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
            'native_runner_label' => $this->nativeRunnerLabel,
            'oidc' => $this->oidc,
            'php_extensions' => $this->phpExtensions,
            'php_version' => $this->phpVersion,
            'target_arch' => $this->targetArch->value,
            'ui_build' => $this->uiBuild,
        ];

        if ($this->imageRepository !== null) {
            $data['image_repository'] = $this->imageRepository;
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
