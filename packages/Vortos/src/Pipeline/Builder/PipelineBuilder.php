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
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;
use Vortos\Pipeline\Registry\RegistryLoginContext;

final class PipelineBuilder
{
    /**
     * In-container path the committed, age-encrypted secrets ciphertext is mounted to and
     * read from. Used as both the `docker run -v` mount target and the value of
     * VORTOS_SECRETS_STORE_PATH so the mount and the store path can never drift. This
     * overrides the image's project-dir default (Secrets\...\SecretsExtension), which would
     * otherwise resolve against WORKDIR and miss the mounted file entirely.
     */
    private const SECRETS_STORE_CONTAINER_PATH = '/app/vortos-secrets.age';

    public function __construct(
        private readonly StageGate $gate,
        private readonly ?CiRegistryLoginProviderRegistry $loginProviders = null,
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
        $steps = [
            new ActionStep('Checkout', KnownActionFactory::checkout()),
            new ActionStep('Setup PHP', KnownActionFactory::setupPhp(), [
                'php-version' => $definition->phpVersion,
                'extensions' => implode(', ', $definition->phpExtensions),
                'coverage' => 'none',
            ]),
            new CommandStep('Install dependencies', 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'),
        ];

        // App-declared steps (migrations, contract checks, seed, …) run after deps install and
        // before the test command — this is what lets the generated workflow replace a real ci.yml.
        foreach ($definition->testSteps as $step) {
            $steps[] = new CommandStep($step['name'], $step['run']);
        }

        $steps[] = new CommandStep('Run tests', $definition->testCommand);

        return new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: $steps,
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            services: $definition->testServiceContainers,
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
                new CommandStep('Run PHPStan', $definition->analyseCommand),
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

        $loginProvider = $this->requireLoginProvider($definition->registryProvider);
        $loginContext = new RegistryLoginContext($definition);

        $archScript = new ArchAssertionScript();
        $steps = [];

        $steps[] = new ActionStep('Checkout', KnownActionFactory::checkout());

        $steps[] = new ActionStep('Set up Docker Buildx', KnownActionFactory::setupBuildx());

        if ($definition->buildMode === BuildMode::BuildxQemu) {
            $steps[] = new ActionStep('Set up QEMU', KnownActionFactory::setupQemu());
        }

        $steps[] = $loginProvider->loginStep($loginContext);

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
            // Guaranteed non-null by PipelineDefinition's constructor invariant (native build stage
            // ⇒ explicit runner label); the throw only guards against an unexpected refactor.
            ? new RunnerSpec(
                label: $definition->nativeRunnerLabel
                    ?? throw new \LogicException('Native build stage reached without a runner label.'),
                archHint: $archValue,
            )
            : new RunnerSpec(label: 'ubuntu-latest');

        $basePermissions = new Permissions([
            new Permission(PermissionScope::Contents, PermissionAccess::Read),
        ]);

        $permissions = $basePermissions->merge($loginProvider->requiredPermissions());

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

    private function requireLoginProvider(string $providerKey): CiRegistryLoginProviderInterface
    {
        if ($this->loginProviders === null) {
            throw new \LogicException(
                'PipelineBuilder requires a CiRegistryLoginProviderRegistry to build image stages. ' .
                'Wire one via the constructor or use PipelineExtension.'
            );
        }

        return $this->loginProviders->provider($providerKey);
    }

    private function deployStage(PipelineDefinition $definition): Stage
    {
        $hasBuild = $definition->hasBuildStage();

        return $hasBuild
            ? $this->deployInImageStage($definition)
            : $this->deployOnRunnerStage($definition);
    }

    /**
     * Deploy-in-image: run record-manifest + doctor + deploy INSIDE the built image via
     * `docker run <repo>@<digest>`, so the app's config and RS256 keys are present by
     * construction — no PHP/composer/keys on the runner. The only CI secret is the age KEK
     * (VORTOS_AGE_IDENTITY); the committed, age-encrypted secrets file is mounted in.
     */
    private function deployInImageStage(PipelineDefinition $definition): Stage
    {
        $repo = $definition->imageRepository;
        \assert($repo !== null);

        $arch = $definition->targetArch->value;
        $imageRef = $repo . '@${{ needs.build.outputs.image }}';

        // Posture-aware secrets. OIDC path (default): zero standing secrets — the deploy
        // credential federates from the runner's OIDC id-token, so the generated workflow
        // references no static secret (enforced by OidcZeroStandingSecretTest). ssh-key path
        // (oidc:false): the encrypted secrets store is opened with the age KEK, the single
        // CI secret, and the committed ciphertext file is mounted in read-only.
        $useAgeKek = !$definition->oidc;
        $stepEnv = $useAgeKek ? ['VORTOS_AGE_IDENTITY' => '${{ secrets.VORTOS_AGE_IDENTITY }}'] : [];

        // The pass-through `-e VAR` forms below forward the deploy connection coordinates from
        // the runner shell into the container; they are populated at the job level from the
        // per-environment GitHub `vars.*` context (see deployConnectionEnv()). In the ssh-key
        // posture the encrypted store is mounted read-only and its in-container path is pinned
        // explicitly so the app does not fall back to the WORKDIR-relative default.
        $secretsMount = $useAgeKek
            ? sprintf(
                '-e VORTOS_AGE_IDENTITY -e VORTOS_SECRETS_STORE_PATH=%s -v "$PWD/vortos-secrets.age:%s:ro" ',
                self::SECRETS_STORE_CONTAINER_PATH,
                self::SECRETS_STORE_CONTAINER_PATH,
            )
            : '';

        $dockerRun = 'docker run --rm '
            . '-e VORTOS_DEPLOY_HOST -e VORTOS_DEPLOY_USER -e VORTOS_DEPLOY_PORT '
            . $secretsMount
            . $imageRef . ' php bin/console ';

        $loginProvider = $this->requireLoginProvider($definition->registryProvider);

        $steps = [
            new ActionStep('Checkout', KnownActionFactory::checkout()),
            $loginProvider->loginStep(new RegistryLoginContext($definition)),
            new CommandStep(
                'Record build manifest',
                $dockerRun . sprintf(
                    'vortos:release:record-manifest --env=${{ matrix.environment }} --repository=%s --digest=${{ needs.build.outputs.image }} --git-sha=${{ github.sha }} --arch=%s --builder-id=github-actions',
                    $repo,
                    $arch,
                ),
                env: $stepEnv,
            ),
            new CommandStep(
                'Run deploy doctor',
                $dockerRun . 'deploy:doctor --env=${{ matrix.environment }} --json',
                env: $stepEnv,
            ),
            new CommandStep(
                'Deploy',
                $dockerRun . sprintf(
                    'deploy --env=${{ matrix.environment }} --yes --json --image-repository=%s --image-digest=${{ needs.build.outputs.image }}',
                    $repo,
                ),
                env: $stepEnv,
            ),
        ];

        return new Stage(
            id: 'deploy',
            displayName: 'Deploy',
            kind: StageKind::Deploy,
            steps: $steps,
            needs: ['tests', 'analyse', 'agnosticism', 'build'],
            runner: new RunnerSpec(),
            permissions: $this->deployPermissions($definition),
            environment: '${{ matrix.environment }}',
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            matrix: $this->environmentMatrix($definition),
            condition: "github.ref == 'refs/heads/main' && github.event_name == 'push'",
            env: $this->deployConnectionEnv(),
        );
    }

    /**
     * Deploy connection coordinates, sourced from the per-environment GitHub `vars.*` context.
     * The deploy job binds `environment: ${{ matrix.environment }}`, so GitHub overlays
     * environment-scoped variables onto repository variables — giving a distinct host/user/port
     * per target environment with zero standing secrets. These are non-secret coordinates, so
     * they live in `vars` (not `secrets`), preserving the OIDC zero-standing-secret invariant.
     * User and port may be left unset upstream; the runtime supplies deploy/22 defaults when the
     * forwarded value is empty (Deploy\...\DeployExtension).
     *
     * @return array<string, string>
     */
    private function deployConnectionEnv(): array
    {
        return [
            'VORTOS_DEPLOY_HOST' => '${{ vars.VORTOS_DEPLOY_HOST }}',
            'VORTOS_DEPLOY_USER' => '${{ vars.VORTOS_DEPLOY_USER }}',
            'VORTOS_DEPLOY_PORT' => '${{ vars.VORTOS_DEPLOY_PORT }}',
        ];
    }

    /**
     * Degenerate path when no image is built (no imageRepository): run on the runner.
     * Without a build there is no image to deploy-in-image, so this exists only so a
     * pipeline without a build stage still emits a coherent (if minimal) deploy job.
     */
    private function deployOnRunnerStage(PipelineDefinition $definition): Stage
    {
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
                env: ['VORTOS_AGE_IDENTITY' => '${{ secrets.VORTOS_AGE_IDENTITY }}'],
            ),
            new CommandStep(
                'Deploy',
                'php bin/console deploy --env=${{ matrix.environment }} --yes --json',
                env: ['VORTOS_AGE_IDENTITY' => '${{ secrets.VORTOS_AGE_IDENTITY }}'],
            ),
        ];

        return new Stage(
            id: 'deploy',
            displayName: 'Deploy',
            kind: StageKind::Deploy,
            steps: $steps,
            needs: ['tests', 'analyse', 'agnosticism'],
            runner: new RunnerSpec(),
            permissions: $this->deployPermissions($definition),
            environment: '${{ matrix.environment }}',
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            matrix: $this->environmentMatrix($definition),
            condition: "github.ref == 'refs/heads/main' && github.event_name == 'push'",
            env: $this->deployConnectionEnv(),
        );
    }

    /**
     * Deploy job permissions: read contents, and — only when the deploy credential
     * federates via OIDC (e.g. ssh-ca-oidc) — request an id-token so the runner can mint
     * a short-lived credential. Without this, OIDC deploy is impossible from the workflow.
     */
    private function deployPermissions(PipelineDefinition $definition): Permissions
    {
        $permissions = new Permissions([
            new Permission(PermissionScope::Contents, PermissionAccess::Read),
        ]);

        if ($definition->oidc) {
            $permissions = $permissions->with(
                new Permission(PermissionScope::IdToken, PermissionAccess::Write),
            );
        }

        return $permissions;
    }

    private function environmentMatrix(PipelineDefinition $definition): Matrix
    {
        return new Matrix(
            axisName: 'environment',
            values: array_map(
                static fn (string $env): array => ['environment' => $env],
                $definition->environments,
            ),
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
