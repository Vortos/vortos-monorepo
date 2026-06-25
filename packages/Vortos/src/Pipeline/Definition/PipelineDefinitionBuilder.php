<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Release\Manifest\Arch;

final class PipelineDefinitionBuilder
{
    private string $emitter = 'github';
    private string $phpVersion = '8.5';
    private ?string $nodeVersion = null;
    /** @var list<string> */
    private array $phpExtensions = ['redis'];
    /** @var list<string> */
    private array $environments = ['production'];
    private bool $benchmark = false;
    private bool $uiBuild = false;
    private ?string $uiBuildPath = null;
    /** @var list<SplitPackage> */
    private array $splitPackageOverrides = [];
    private int $defaultTimeoutMinutes = 30;
    private Arch $targetArch = Arch::Arm64;
    private ?string $imageRepository = null;
    private BuildMode $buildMode = BuildMode::Native;
    private string $nativeRunnerLabel = 'ubuntu-24.04-arm64';
    private ?bool $oidc = null;
    private ?string $baseImageDigest = null;
    private bool $emitSbom = true;
    private string $dockerfilePath = 'Dockerfile';

    public function emitter(string $emitter): self
    {
        $clone = clone $this;
        $clone->emitter = $emitter;

        return $clone;
    }

    public function phpVersion(string $version): self
    {
        $clone = clone $this;
        $clone->phpVersion = $version;

        return $clone;
    }

    public function nodeVersion(string $version): self
    {
        $clone = clone $this;
        $clone->nodeVersion = $version;

        return $clone;
    }

    /** @param list<string> $extensions */
    public function phpExtensions(array $extensions): self
    {
        $clone = clone $this;
        $clone->phpExtensions = $extensions;

        return $clone;
    }

    /** @param list<string> $environments */
    public function environments(array $environments): self
    {
        $clone = clone $this;
        $clone->environments = $environments;

        return $clone;
    }

    public function benchmark(bool $enabled): self
    {
        $clone = clone $this;
        $clone->benchmark = $enabled;

        return $clone;
    }

    public function uiBuild(bool $enabled, ?string $path = null): self
    {
        $clone = clone $this;
        $clone->uiBuild = $enabled;
        $clone->uiBuildPath = $path;

        return $clone;
    }

    /** @param list<SplitPackage> $packages */
    public function splitPackages(array $packages): self
    {
        $clone = clone $this;
        $clone->splitPackageOverrides = $packages;

        return $clone;
    }

    public function defaultTimeoutMinutes(int $minutes): self
    {
        $clone = clone $this;
        $clone->defaultTimeoutMinutes = $minutes;

        return $clone;
    }

    public function targetArch(Arch $arch): self
    {
        $clone = clone $this;
        $clone->targetArch = $arch;

        return $clone;
    }

    public function imageRepository(string $repository): self
    {
        $clone = clone $this;
        $clone->imageRepository = $repository;

        return $clone;
    }

    public function buildMode(BuildMode $mode): self
    {
        $clone = clone $this;
        $clone->buildMode = $mode;

        return $clone;
    }

    public function nativeRunnerLabel(string $label): self
    {
        $clone = clone $this;
        $clone->nativeRunnerLabel = $label;

        return $clone;
    }

    public function oidc(bool $enabled): self
    {
        $clone = clone $this;
        $clone->oidc = $enabled;

        return $clone;
    }

    public function baseImageDigest(string $digest): self
    {
        $clone = clone $this;
        $clone->baseImageDigest = $digest;

        return $clone;
    }

    public function emitSbom(bool $enabled): self
    {
        $clone = clone $this;
        $clone->emitSbom = $enabled;

        return $clone;
    }

    public function dockerfilePath(string $path): self
    {
        $clone = clone $this;
        $clone->dockerfilePath = $path;

        return $clone;
    }

    public function build(): PipelineDefinition
    {
        return new PipelineDefinition(
            emitter: $this->emitter,
            phpVersion: $this->phpVersion,
            nodeVersion: $this->nodeVersion,
            phpExtensions: $this->phpExtensions,
            environments: $this->environments,
            benchmark: $this->benchmark,
            uiBuild: $this->uiBuild,
            uiBuildPath: $this->uiBuildPath,
            splitPackageOverrides: $this->splitPackageOverrides,
            defaultTimeoutMinutes: $this->defaultTimeoutMinutes,
            targetArch: $this->targetArch,
            imageRepository: $this->imageRepository,
            buildMode: $this->buildMode,
            nativeRunnerLabel: $this->nativeRunnerLabel,
            oidc: $this->oidc,
            baseImageDigest: $this->baseImageDigest,
            emitSbom: $this->emitSbom,
            dockerfilePath: $this->dockerfilePath,
        );
    }
}
