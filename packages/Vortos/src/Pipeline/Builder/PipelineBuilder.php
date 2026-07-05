<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\QualityMode;
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
            triggers: $this->buildTriggers($definition),
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
            // Optional quality stages: an app with no PHPStan / no agnosticism tests can turn these
            // off (or run them non-blocking in `warn` mode) rather than ship a red pipeline (G6).
            StageKind::StaticAnalysis => $this->emitsStaticAnalysis($definition) ? $this->staticAnalysisStage($definition) : null,
            StageKind::Agnosticism => $this->emitsAgnosticism($definition) ? $this->agnosticismStage($definition) : null,
            StageKind::Deploy => $this->deployStage($definition),
            default => null,
        };
    }

    /**
     * Shell steps injected into every container-booting job (test, static-analysis, agnosticism),
     * after `composer install` and before the stage command — e.g. `cp .env.example .env` so the DI
     * container can compile `%env()%` references (B6/G6).
     *
     * @return list<CommandStep>
     */
    private function bootstrapSteps(PipelineDefinition $definition): array
    {
        return array_map(
            static fn (array $step): CommandStep => new CommandStep($step['name'], $step['run']),
            $definition->bootstrapSteps,
        );
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
            ...$this->bootstrapSteps($definition),
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
                ...$this->bootstrapSteps($definition),
                new CommandStep('Run PHPStan', $this->qualityCommand(
                    $definition->staticAnalysisMode,
                    $definition->analyseCommand,
                    'PHPStan',
                )),
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
                ...$this->bootstrapSteps($definition),
                new CommandStep('Run agnosticism lint', $this->qualityCommand(
                    $definition->agnosticismMode,
                    './vendor/bin/phpunit --testdox --filter Agnosticism',
                    'Agnosticism lint',
                )),
            ],
            needs: ['tests'],
            runner: new RunnerSpec(),
            permissions: Permissions::readOnly(),
            timeoutMinutes: $definition->defaultTimeoutMinutes,
        );
    }

    private function emitsStaticAnalysis(PipelineDefinition $definition): bool
    {
        return $definition->emitStaticAnalysis && $definition->staticAnalysisMode->emits();
    }

    private function emitsAgnosticism(PipelineDefinition $definition): bool
    {
        return $definition->emitAgnosticism && $definition->agnosticismMode->emits();
    }

    /**
     * The shell for a quality stage's command. In `enforce` mode the command runs as-is (fail-closed).
     * In `warn` mode it is guarded on the tool being installed (skip cleanly if absent) and made
     * non-failing (issues surface as GitHub warnings), so an app adopting the tool never ships a red
     * pipeline (G6).
     */
    private function qualityCommand(QualityMode $mode, string $command, string $label): string
    {
        if ($mode !== QualityMode::Warn) {
            return $command;
        }

        $tool = $this->toolBinary($command);

        return sprintf(
            'command -v %1$s >/dev/null 2>&1 || { echo "::warning::%2$s tool (%1$s) not installed — stage skipped"; exit 0; }; '
            . '%3$s || echo "::warning::%2$s reported issues (warn mode; not failing the build)"',
            $tool,
            $label,
            $command,
        );
    }

    /** The executable a command invokes — the first whitespace-delimited token. */
    private function toolBinary(string $command): string
    {
        $trimmed = ltrim($command);
        $spacePos = strpos($trimmed, ' ');

        return $spacePos === false ? $trimmed : substr($trimmed, 0, $spacePos);
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
            needs: $this->qualityStageIds($definition),
            runner: $runner,
            permissions: $permissions,
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            outputs: ['image' => '${{ steps.image.outputs.digest }}'],
            condition: $this->pushToDeploymentBranchCondition($definition) . " || github.ref_type == 'tag'",
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
     * Deploy-on-target: the runner opens SSH to the VPS and runs record-manifest + provision +
     * doctor + deploy INSIDE the built image **on the VPS**, attached to the app's Docker network
     * (G1 + B9). The commands reach the production release-ledger DB by construction, and the
     * arm64-native image runs on the arm64 host — never `docker run` on the amd64 runner.
     *
     * Posture: ssh-key (oidc:false) authenticates with `secrets.VORTOS_DEPLOY_SSH_KEY` and forwards
     * the age KEK; OIDC (default) federates a short-lived SSH certificate from the runner's id-token
     * against a configurable CA (non-secret `vars.*`) — preserving the zero-standing-secret invariant.
     */
    private function deployInImageStage(PipelineDefinition $definition): Stage
    {
        $repo = $definition->imageRepository;
        \assert($repo !== null);

        $digestExpr = '${{ needs.build.outputs.image }}';
        $imageRef = $repo . '@' . $digestExpr;
        $envExpr = '${{ matrix.environment }}';

        $remoteScript = (new RemoteDeployScript())->build($definition, $imageRef, $repo, $digestExpr, $envExpr);

        $steps = [
            new ActionStep('Checkout', KnownActionFactory::checkout()),
            $this->sshSetupStep($definition),
            new CommandStep('Deploy on target over SSH', $this->sshInvocation($remoteScript)),
        ];

        return new Stage(
            id: 'deploy',
            displayName: 'Deploy',
            kind: StageKind::Deploy,
            steps: $steps,
            needs: [...$this->qualityStageIds($definition), 'build'],
            // The runner only runs an ssh client; it never `docker run`s the arm64 image (B9).
            runner: new RunnerSpec(),
            permissions: $this->deployPermissions($definition),
            environment: '${{ matrix.environment }}',
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            matrix: $this->environmentMatrix($definition),
            condition: $this->pushToDeploymentBranchCondition($definition),
            env: $this->deployConnectionEnv(),
        );
    }

    /**
     * Posture-aware SSH credential setup on the runner. ssh-key posture materialises a 0600 private
     * key from the single CI secret plus a strict known_hosts; OIDC posture federates a short-lived
     * certificate from the id-token against a configurable CA — referencing only non-secret `vars.*`
     * so the zero-standing-secret invariant holds.
     */
    private function sshSetupStep(PipelineDefinition $definition): CommandStep
    {
        if ($definition->oidc) {
            $run = <<<'SH'
                mkdir -p ~/.ssh && chmod 700 ~/.ssh
                printf '%s\n' "${{ vars.VORTOS_DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts && chmod 600 ~/.ssh/known_hosts
                # OIDC federation: exchange the runner id-token for a short-lived SSH certificate.
                # Zero standing secrets — the CA endpoint and principals are non-secret vars.
                ID_TOKEN="$(curl -sS -H "Authorization: bearer $ACTIONS_ID_TOKEN_REQUEST_TOKEN" \
                  "$ACTIONS_ID_TOKEN_REQUEST_URL&audience=${{ vars.VORTOS_SSH_CA_AUDIENCE }}" | jq -r '.value')"
                ssh-keygen -q -t ed25519 -N '' -f ~/.ssh/vortos_deploy
                curl -sS -X POST "${{ vars.VORTOS_SSH_CA_URL }}" \
                  -H "Authorization: Bearer $ID_TOKEN" \
                  --data-binary @~/.ssh/vortos_deploy.pub > ~/.ssh/vortos_deploy-cert.pub
                chmod 600 ~/.ssh/vortos_deploy-cert.pub
                SH;
        } else {
            $run = <<<'SH'
                mkdir -p ~/.ssh && chmod 700 ~/.ssh
                printf '%s\n' "${{ secrets.VORTOS_DEPLOY_SSH_KEY }}" > ~/.ssh/vortos_deploy && chmod 600 ~/.ssh/vortos_deploy
                printf '%s\n' "${{ vars.VORTOS_DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts && chmod 600 ~/.ssh/known_hosts
                SH;
        }

        return new CommandStep('Set up SSH to the deploy target', $run);
    }

    /**
     * Wrap the remote deploy script in a strict-host-key SSH invocation, piping it to `bash -s` on
     * the VPS via a quoted heredoc. GitHub expands every `${{ … }}` before bash runs, so the script
     * shipped over the channel is fully resolved; the quoted delimiter stops the runner shell from
     * re-expanding it.
     */
    private function sshInvocation(string $remoteScript): string
    {
        $ssh = 'ssh -i ~/.ssh/vortos_deploy '
            . '-o StrictHostKeyChecking=yes -o UserKnownHostsFile=~/.ssh/known_hosts '
            . '-p "${VORTOS_DEPLOY_PORT:-22}" "${VORTOS_DEPLOY_USER:-deploy}@${VORTOS_DEPLOY_HOST}"';

        return $ssh . " 'bash -euo pipefail -s' <<'VORTOS_REMOTE'\n" . rtrim($remoteScript, "\n") . "\nVORTOS_REMOTE\n";
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
            needs: $this->qualityStageIds($definition),
            runner: new RunnerSpec(),
            permissions: $this->deployPermissions($definition),
            environment: '${{ matrix.environment }}',
            timeoutMinutes: $definition->defaultTimeoutMinutes,
            matrix: $this->environmentMatrix($definition),
            condition: $this->pushToDeploymentBranchCondition($definition),
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
        // Scalar axis: `matrix.environment: [production, staging]`, referenced as the scalar
        // `${{ matrix.environment }}` throughout the deploy job. Emitting objects here
        // (`[{environment: production}]`) while referencing a scalar is what made GitHub resolve a
        // stringified object and fail to initialise the deploy job (B8).
        return new Matrix(
            axisName: 'environment',
            values: $definition->environments,
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
                    'branch' => sprintf('${{ github.ref_type == \'branch\' && github.ref_name || \'%s\' }}', $definition->deploymentBranch),
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
            condition: sprintf("github.ref == 'refs/heads/%s' || github.ref_type == 'tag'", $definition->deploymentBranch),
        )];
    }

    /** @return list<Trigger> */
    private function buildTriggers(PipelineDefinition $definition): array
    {
        return [
            new Trigger(TriggerEvent::Push, branches: [$definition->deploymentBranch], tags: ['*']),
            new Trigger(TriggerEvent::PullRequest),
        ];
    }

    /** A push to the configured deployment branch (B3). */
    private function pushToDeploymentBranchCondition(PipelineDefinition $definition): string
    {
        return sprintf(
            "github.ref == 'refs/heads/%s' && github.event_name == 'push'",
            $definition->deploymentBranch,
        );
    }

    /**
     * The upstream quality jobs a build/deploy job must wait on. `analyse` and `agnosticism` are
     * only listed when actually emitted (G6) — otherwise the generated workflow would `needs:` a
     * job that does not exist and refuse to run.
     *
     * @return list<string>
     */
    private function qualityStageIds(PipelineDefinition $definition): array
    {
        $ids = ['tests'];
        if ($this->emitsStaticAnalysis($definition)) {
            $ids[] = 'analyse';
        }
        if ($this->emitsAgnosticism($definition)) {
            $ids[] = 'agnosticism';
        }

        return $ids;
    }
}
