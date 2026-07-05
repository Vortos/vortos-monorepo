<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\ServiceContainer;
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
    private ?string $nativeRunnerLabel = null;
    private ?bool $oidc = null;
    private ?string $baseImageDigest = null;
    private bool $emitSbom = true;
    private string $dockerfilePath = 'Dockerfile';
    private bool $emitScanGate = false;
    private bool $emitSign = false;
    private string $registryProvider = 'ghcr';
    private string $workflowFilename = 'ci.yml';
    private ?string $workflowName = null;
    private string $testCommand = './vendor/bin/phpunit --testdox';
    private string $analyseCommand = './vendor/bin/phpstan analyse';
    /** @var list<ServiceContainer> */
    private array $testServiceContainers = [];
    /** @var list<array{name: string, run: string}> */
    private array $testSteps = [];
    private string $deploymentBranch = 'main';
    private string $remoteDeployDir = '/opt/vortos';
    private string $appNetwork = 'vortos-net';
    /** @var list<string> */
    private array $runtimeEnvFiles = ['/opt/vortos/.env.prod'];
    /** @var list<string> */
    private array $runtimeFileSecretDirs = [];
    /** @var list<array{name: string, run: string}> */
    private array $bootstrapSteps = [];
    private bool $emitStaticAnalysis = true;
    private bool $emitAgnosticism = true;
    private QualityMode $staticAnalysisMode = QualityMode::Enforce;
    private QualityMode $agnosticismMode = QualityMode::Enforce;

    public static function create(): self
    {
        return new self();
    }

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

    public function emitScanGate(bool $enabled): self
    {
        $clone = clone $this;
        $clone->emitScanGate = $enabled;

        return $clone;
    }

    public function emitSign(bool $enabled): self
    {
        $clone = clone $this;
        $clone->emitSign = $enabled;

        return $clone;
    }

    public function registryProvider(string $provider): self
    {
        $clone = clone $this;
        $clone->registryProvider = $provider;

        return $clone;
    }

    public function workflowFilename(string $filename): self
    {
        $clone = clone $this;
        $clone->workflowFilename = $filename;

        return $clone;
    }

    public function workflowName(?string $name): self
    {
        $clone = clone $this;
        $clone->workflowName = $name;

        return $clone;
    }

    public function testCommand(string $command): self
    {
        $clone = clone $this;
        $clone->testCommand = $command;

        return $clone;
    }

    public function analyseCommand(string $command): self
    {
        $clone = clone $this;
        $clone->analyseCommand = $command;

        return $clone;
    }

    /** @param list<ServiceContainer> $containers */
    public function testServiceContainers(array $containers): self
    {
        $clone = clone $this;
        $clone->testServiceContainers = $containers;

        return $clone;
    }

    /** @param list<array{name: string, run: string}> $steps */
    public function testSteps(array $steps): self
    {
        $clone = clone $this;
        $clone->testSteps = $steps;

        return $clone;
    }

    public function deploymentBranch(string $branch): self
    {
        $clone = clone $this;
        $clone->deploymentBranch = $branch;

        return $clone;
    }

    public function remoteDeployDir(string $dir): self
    {
        $clone = clone $this;
        $clone->remoteDeployDir = $dir;

        return $clone;
    }

    public function appNetwork(string $network): self
    {
        $clone = clone $this;
        $clone->appNetwork = $network;

        return $clone;
    }

    /**
     * Absolute env-file paths (on the target host) the blue/green color reads; each is bind-mounted
     * read-only into the deploy one-shot so the nested cutover compose can resolve its `env_file:`
     * (B19). Must match config/deploy.php's RuntimeServiceSpec envFiles.
     *
     * @param list<string> $paths
     */
    public function runtimeEnvFiles(array $paths): self
    {
        $clone = clone $this;
        $clone->runtimeEnvFiles = array_values($paths);

        return $clone;
    }

    /**
     * Host tmpfs directories the deploy one-shot materialises file-shaped secrets into (G8). Must be
     * under /run/ or /dev/shm/.
     *
     * @param list<string> $dirs
     */
    public function runtimeFileSecretDirs(array $dirs): self
    {
        $clone = clone $this;
        $clone->runtimeFileSecretDirs = array_values($dirs);

        return $clone;
    }

    /** @param list<array{name: string, run: string}> $steps */
    public function bootstrapSteps(array $steps): self
    {
        $clone = clone $this;
        $clone->bootstrapSteps = $steps;

        return $clone;
    }

    /**
     * Convenience: prepend a `cp <from> <to>` bootstrap step so boot-dependent jobs (test,
     * static-analysis, agnosticism) have an env file before the DI container compiles `%env()%`.
     */
    public function prepareEnvFile(string $from = '.env.example', string $to = '.env'): self
    {
        $clone = clone $this;
        $clone->bootstrapSteps = [
            ['name' => 'Prepare application env file', 'run' => sprintf('cp %s %s', $from, $to)],
            ...$clone->bootstrapSteps,
        ];

        return $clone;
    }

    public function emitStaticAnalysis(bool $enabled): self
    {
        $clone = clone $this;
        $clone->emitStaticAnalysis = $enabled;

        return $clone;
    }

    public function emitAgnosticism(bool $enabled): self
    {
        $clone = clone $this;
        $clone->emitAgnosticism = $enabled;

        return $clone;
    }

    public function staticAnalysisMode(QualityMode $mode): self
    {
        $clone = clone $this;
        $clone->staticAnalysisMode = $mode;

        return $clone;
    }

    public function agnosticismMode(QualityMode $mode): self
    {
        $clone = clone $this;
        $clone->agnosticismMode = $mode;

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
            emitScanGate: $this->emitScanGate,
            emitSign: $this->emitSign,
            registryProvider: $this->registryProvider,
            workflowFilename: $this->workflowFilename,
            workflowName: $this->workflowName,
            testCommand: $this->testCommand,
            analyseCommand: $this->analyseCommand,
            testServiceContainers: $this->testServiceContainers,
            testSteps: $this->testSteps,
            deploymentBranch: $this->deploymentBranch,
            remoteDeployDir: $this->remoteDeployDir,
            appNetwork: $this->appNetwork,
            runtimeEnvFiles: $this->runtimeEnvFiles,
            runtimeFileSecretDirs: $this->runtimeFileSecretDirs,
            bootstrapSteps: $this->bootstrapSteps,
            emitStaticAnalysis: $this->emitStaticAnalysis,
            emitAgnosticism: $this->emitAgnosticism,
            staticAnalysisMode: $this->staticAnalysisMode,
            agnosticismMode: $this->agnosticismMode,
        );
    }
}
