<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Matrix;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\RunnerSpec;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageCatalog;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class PipelineBuilder
{
    public function __construct(
        private readonly StageGate $gate,
    ) {}

    /**
     * @param list<SplitPackage> $splitPackages
     */
    public function build(PipelineDefinition $definition, array $splitPackages = []): Pipeline
    {
        $stages = $this->buildStages($definition);
        $splitStages = $this->buildSplitStages($definition, $splitPackages);

        return new Pipeline(
            name: 'CI',
            triggers: $this->buildTriggers(),
            stages: [...$stages, ...$splitStages],
            permissions: Permissions::readOnly(),
            concurrencyGroup: '${{ github.workflow }}-${{ github.ref }}',
            concurrencyCancelInProgress: true,
        );
    }

    /** @return list<Stage> */
    private function buildStages(PipelineDefinition $definition): array
    {
        $stages = [];

        foreach (StageCatalog::standard() as $kind) {
            if ($kind === StageKind::Build) {
                $buildStage = $this->buildImageStage($definition);
                if ($buildStage !== null) {
                    $stages[] = $buildStage;
                }
                continue;
            }

            if (!$this->gate->shouldEmit($kind)) {
                continue;
            }

            $stage = $this->buildStage($kind, $definition);
            if ($stage !== null) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    private function buildStage(StageKind $kind, PipelineDefinition $definition): ?Stage
    {
        return match ($kind) {
            StageKind::Test => $this->testStage($definition),
            StageKind::StaticAnalysis => $this->staticAnalysisStage($definition),
            StageKind::Agnosticism => $this->agnosticismStage($definition),
            StageKind::Deploy => $this->deployStage($definition),
            default => null,
        };
    }

    private function testStage(PipelineDefinition $definition): Stage
    {
        return new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [
                new ActionStep('Checkout', KnownActionFactory::checkout()),
                new ActionStep('Setup PHP', KnownActionFactory::setupPhp(), [
                    'php-version' => $definition->phpVersion,
                    'extensions' => implode(', ', $definition->phpExtensions),
                    'coverage' => 'none',
                ]),
                new CommandStep('Install dependencies', 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'),
                new CommandStep('Run tests', './vendor/bin/phpunit --testdox'),
            ],
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            timeoutMinutes: $definition->defaultTimeoutMinutes,
        );
    }

    private function staticAnalysisStage(PipelineDefinition $definition): Stage
    {
        return new Stage(
            id: 'analyse',
            displayName: 'Static Analysis',
            kind: StageKind::StaticAnalysis,
            steps: [
                new ActionStep('Checkout', KnownActionFactory::checkout()),
                new ActionStep('Setup PHP', KnownActionFactory::setupPhp(), [
                    'php-version' => $definition->phpVersion,
                    'extensions' => implode(', ', $definition->phpExtensions),
                    'coverage' => 'none',
                ]),
                new CommandStep('Install dependencies', 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'),
                new CommandStep('Run PHPStan', './vendor/bin/phpstan analyse'),
            ],
            needs: ['tests'],
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            timeoutMinutes: $definition->defaultTimeoutMinutes,
        );
    }

    private function agnosticismStage(PipelineDefinition $definition): Stage
    {
        return new Stage(
            id: 'agnosticism',
            displayName: 'Agnosticism Lint',
            kind: StageKind::Agnosticism,
            steps: [
                new ActionStep('Checkout', KnownActionFactory::checkout()),
                new ActionStep('Setup PHP', KnownActionFactory::setupPhp(), [
                    'php-version' => $definition->phpVersion,
                    'extensions' => implode(', ', $definition->phpExtensions),
                    'coverage' => 'none',
                ]),
                new CommandStep('Install dependencies', 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'),
                new CommandStep('Run agnosticism lint', './vendor/bin/phpunit --testdox --filter Agnosticism'),
            ],
            needs: ['tests'],
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            timeoutMinutes: $definition->defaultTimeoutMinutes,
        );
    }

    private function buildImageStage(PipelineDefinition $definition): ?Stage
    {
        if (!$definition->hasBuildStage()) {
            return null;
        }

        $repo = $definition->imageRepository;
        \assert($repo !== null);

        $archScript = new ArchAssertionScript();
        $steps = [];

        $steps[] = new ActionStep('Checkout', KnownActionFactory::checkout());

        $steps[] = new ActionStep('Set up Docker Buildx', KnownActionFactory::setupBuildx());

        if ($definition->buildMode === BuildMode::BuildxQemu) {
            $steps[] = new ActionStep('Set up QEMU', KnownActionFactory::setupQemu());
        }

        if ($definition->oidc) {
            $steps[] = new CommandStep(
                'Registry login via OIDC',
                "echo \"\${{ secrets.GITHUB_TOKEN }}\" | docker login ghcr.io -u \${{ github.actor }} --password-stdin",
            );
        } else {
            $steps[] = new CommandStep(
                'Registry login',
                "echo \"\${{ secrets.GITHUB_TOKEN }}\" | docker login ghcr.io -u \${{ github.actor }} --password-stdin",
            );
        }

        $archValue = $definition->targetArch->value;

        $buildWith = [
            'context' => '.',
            'file' => $definition->dockerfilePath,
            'platforms' => $archValue,
            'push' => 'true',
            'provenance' => 'true',
            'sbom' => $definition->emitSbom ? 'true' : 'false',
            'tags' => $repo . ':sha-${{ github.sha }}',
        ];

        if ($definition->baseImageDigest !== null) {
            $buildWith['build-args'] = 'BASE_IMAGE_DIGEST=' . $definition->baseImageDigest;
        }

        $steps[] = new ActionStep(
            'Build and push',
            KnownActionFactory::buildPush(),
            $buildWith,
            id: 'build',
        );

        $digestRef = $repo . '@${{ steps.build.outputs.digest }}';
        $steps[] = new CommandStep(
            'Verify architecture',
            $archScript->generate($digestRef, $definition->targetArch),
            id: 'archcheck',
        );

        if ($definition->baseImageDigest === null) {
            $steps[] = new CommandStep(
                'Base image digest drift warning',
                "echo \"::warning::No base image digest pinned — build reproducibility is not guaranteed. Pin via baseImageDigest in PipelineDefinition.\"",
            );
        }

        $steps[] = new CommandStep(
            'Expose digest',
            'echo "digest=${{ steps.build.outputs.digest }}" >> "$GITHUB_OUTPUT"',
            id: 'image',
        );

        if ($definition->emitSbom) {
            $steps[] = new ActionStep(
                'Generate SBOM',
                KnownActionFactory::sbomAttest(),
                ['image' => $digestRef, 'format' => 'spdx-json'],
            );
        }

        $semverTagStep = new CommandStep(
            'Tag with release version',
            "docker buildx imagetools create --tag {$repo}:\${{ github.ref_name }} {$digestRef}",
            condition: "github.ref_type == 'tag'",
        );
        $steps[] = $semverTagStep;

        $runner = $definition->buildMode === BuildMode::Native
            ? new RunnerSpec(label: $definition->nativeRunnerLabel, archHint: $archValue)
            : new RunnerSpec(label: 'ubuntu-latest');

        $permissions = new Permissions([
            new Permission(PermissionScope::Contents, PermissionAccess::Read),
            new Permission(PermissionScope::Packages, PermissionAccess::Write),
        ]);

        if ($definition->oidc) {
            $permissions = $permissions->with(
                new Permission(PermissionScope::IdToken, PermissionAccess::Write),
            );
        }

        return new Stage(
            id: 'build',
            displayName: 'Build & Push Image',
            kind: StageKind::Build,
            steps: $steps,
            needs: ['tests', 'analyse', 'agnosticism'],
            runner: $runner,
            permissions: $permissions,
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            outputs: ['image' => '${{ steps.image.outputs.digest }}'],
            condition: "github.ref == 'refs/heads/main' && github.event_name == 'push' || github.ref_type == 'tag'",
        );
    }

    private function deployStage(PipelineDefinition $definition): Stage
    {
        $hasBuild = $definition->hasBuildStage();

        $steps = [
            new ActionStep('Checkout', KnownActionFactory::checkout()),
            new ActionStep('Setup PHP', KnownActionFactory::setupPhp(), [
                'php-version' => $definition->phpVersion,
                'extensions' => implode(', ', $definition->phpExtensions),
                'coverage' => 'none',
            ]),
            new CommandStep('Install dependencies', 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'),
            new CommandStep(
                'Run deploy doctor',
                'php bin/console deploy:doctor --env=${{ matrix.environment }} --json',
            ),
        ];

        $deployCmd = 'php bin/console deploy --env=${{ matrix.environment }} --yes --json';
        if ($hasBuild) {
            $deployCmd .= ' --image-digest=${{ needs.build.outputs.image }}';
        }

        $steps[] = new CommandStep('Deploy', $deployCmd);

        $needs = ['tests', 'analyse', 'agnosticism'];
        if ($hasBuild) {
            $needs[] = 'build';
        }

        return new Stage(
            id: 'deploy',
            displayName: 'Deploy',
            kind: StageKind::Deploy,
            steps: $steps,
            needs: $needs,
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            environment: '${{ matrix.environment }}',
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            matrix: new Matrix(
                axisName: 'environment',
                values: array_map(
                    static fn (string $env): array => ['environment' => $env],
                    $definition->environments,
                ),
            ),
            condition: "github.ref == 'refs/heads/main' && github.event_name == 'push'",
        );
    }

    /**
     * @param list<SplitPackage> $splitPackages
     * @return list<Stage>
     */
    private function buildSplitStages(PipelineDefinition $definition, array $splitPackages): array
    {
        if ($splitPackages === []) {
            return [];
        }

        $needs = ['tests'];

        return [new Stage(
            id: 'split',
            displayName: 'Monorepo Split',
            kind: StageKind::Split,
            steps: [
                new ActionStep('Checkout', KnownActionFactory::checkout(), [
                    'fetch-depth' => '0',
                ]),
                new ActionStep('Split', KnownActionFactory::monorepoSplit(), [
                    'package_directory' => '${{ matrix.package.local_path }}',
                    'repository_organization' => 'Vortos',
                    'repository_name' => '${{ matrix.package.split_repository }}',
                    'user_name' => 'Sachintha De Silva',
                    'user_email' => 'yslaksura@gmail.com',
                    'tag' => '${{ github.ref_type == \'tag\' && github.ref_name || \'\' }}',
                    'branch' => '${{ github.ref_type == \'branch\' && github.ref_name || \'main\' }}',
                ]),
            ],
            needs: $needs,
            runner: new RunnerSpec(),
            permissions: new Permissions([
                new Permission(PermissionScope::Contents, PermissionAccess::Read),
            ]),
            matrix: new Matrix(
                axisName: 'package',
                values: array_map(
                    static fn (SplitPackage $p): array => $p->toArray(),
                    $splitPackages,
                ),
                failFast: false,
            ),
            condition: "github.ref == 'refs/heads/main' || github.ref_type == 'tag'",
        )];
    }

    /** @return list<Trigger> */
    private function buildTriggers(): array
    {
        return [
            new Trigger(TriggerEvent::Push, branches: ['main'], tags: ['*']),
            new Trigger(TriggerEvent::PullRequest),
        ];
    }
}
