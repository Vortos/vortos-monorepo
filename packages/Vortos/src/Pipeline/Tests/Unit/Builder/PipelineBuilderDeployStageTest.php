<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\DockerHubCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GcpArtifactRegistryCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

final class PipelineBuilderDeployStageTest extends TestCase
{
    private function buildPipeline(PipelineDefinition $definition): Pipeline
    {
        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));

        return (new PipelineBuilder(new StageGate(), $registry))->build($definition);
    }

    private function deployStage(PipelineDefinition $definition): Stage
    {
        foreach ($this->buildPipeline($definition)->stages as $stage) {
            if ($stage->kind === StageKind::Deploy) {
                return $stage;
            }
        }

        self::fail('No deploy stage was emitted.');
    }

    private function withBuild(): PipelineDefinition
    {
        return new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        );
    }

    /** @return list<CommandStep> */
    private function commandSteps(Stage $stage): array
    {
        return array_values(array_filter($stage->steps, static fn ($s): bool => $s instanceof CommandStep));
    }

    public function test_oidc_deploy_requests_id_token_write(): void
    {
        // imageRepository set => oidc defaults true.
        $stage = $this->deployStage($this->withBuild());

        $perms = $stage->permissions->toArray();
        $this->assertSame('read', $perms['contents'] ?? null);
        $this->assertSame('write', $perms['id-token'] ?? null, 'OIDC deploy must request id-token: write');
    }

    public function test_non_oidc_deploy_is_read_only(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        );

        $stage = $this->deployStage($definition);
        $perms = $stage->permissions->toArray();

        $this->assertSame('read', $perms['contents'] ?? null);
        $this->assertArrayNotHasKey('id-token', $perms);
    }

    /** The single command step that SSHes to the VPS and runs the remote deploy script. */
    private function remoteStep(PipelineDefinition $definition): CommandStep
    {
        foreach ($this->commandSteps($this->deployStage($definition)) as $step) {
            if (str_contains($step->run, 'bash -euo pipefail -s')) {
                return $step;
            }
        }

        self::fail('No remote-exec deploy step was emitted.');
    }

    public function test_deploy_runs_on_the_target_over_ssh_never_on_the_runner(): void
    {
        // G1 + B9: the image is pulled and executed on the VPS (arm host, app network), never
        // `docker run` on the amd64 runner.
        $stage = $this->deployStage($this->withBuild());
        $remote = $this->remoteStep($this->withBuild());

        self::assertSame('ubuntu-latest', $stage->runner->label);
        self::assertStringContainsString('ssh -i ~/.ssh/vortos_deploy', $remote->run);
        self::assertStringContainsString("'bash -euo pipefail -s' <<'VORTOS_REMOTE'", $remote->run);
        self::assertStringContainsString('--network vortos-net', $remote->run);
        // The runner shell must never itself run the arch-specific image.
        foreach ($this->commandSteps($this->deployStage($this->withBuild())) as $step) {
            self::assertStringNotContainsString('docker run --rm ghcr.io/acme/app@', $step->run);
        }
    }

    public function test_deploy_records_manifest_before_deploying(): void
    {
        $run = $this->remoteStep($this->withBuild())->run;

        $recordIdx = strpos($run, 'vortos:release:record-manifest');
        $deployIdx = strpos($run, 'php bin/console deploy --env=');

        $this->assertIsInt($recordIdx, 'record-manifest must be emitted');
        $this->assertIsInt($deployIdx, 'deploy must be emitted');
        $this->assertLessThan($deployIdx, $recordIdx, 'record-manifest must run before deploy');
    }

    public function test_deploy_pulls_and_runs_the_pinned_reference_on_the_target(): void
    {
        $run = $this->remoteStep($this->withBuild())->run;

        $this->assertStringContainsString('docker pull ghcr.io/acme/app@${{ needs.build.outputs.image }}', $run);
        $this->assertStringContainsString('ghcr.io/acme/app@${{ needs.build.outputs.image }} php bin/console', $run);
    }

    public function test_deploy_passes_image_repository_and_digest(): void
    {
        $run = $this->remoteStep($this->withBuild())->run;

        $this->assertStringContainsString('--image-repository=ghcr.io/acme/app', $run);
        $this->assertStringContainsString('--image-digest=${{ needs.build.outputs.image }}', $run);
    }

    public function test_oidc_default_deploy_references_no_standing_secret(): void
    {
        // Default posture (oidc true): zero standing secrets — no age KEK, no mounted store.
        $run = $this->remoteStep($this->withBuild())->run;

        $this->assertStringNotContainsString('VORTOS_AGE_IDENTITY', $run);
        $this->assertStringNotContainsString('vortos-secrets.age', $run);
    }

    public function test_ssh_key_posture_forwards_age_kek_and_mounts_delivered_store(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        );

        $run = $this->remoteStep($definition)->run;

        $this->assertStringContainsString('export VORTOS_AGE_IDENTITY=\'${{ secrets.VORTOS_AGE_IDENTITY }}\'', $run);
        $this->assertStringContainsString('-e VORTOS_SECRETS_STORE_PATH=/app/vortos-secrets.age', $run);
        $this->assertStringContainsString('-v /opt/vortos/vortos-secrets.age:/app/vortos-secrets.age:ro', $run);
    }

    public function test_ssh_key_posture_uses_deploy_ssh_key_secret(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        );

        $allRuns = implode("\n", array_map(static fn (CommandStep $s): string => $s->run, $this->commandSteps($this->deployStage($definition))));
        $this->assertStringContainsString('secrets.VORTOS_DEPLOY_SSH_KEY', $allRuns);
    }

    public function test_deploy_job_sources_connection_coordinates_from_environment_vars(): void
    {
        // Both postures must publish host/user/port at the job level from the per-environment
        // GitHub vars context; the docker `-e VAR` pass-through has nothing to forward otherwise.
        foreach ([true, false] as $oidc) {
            $stage = $this->deployStage(new PipelineDefinition(
                environments: ['production'],
                imageRepository: 'ghcr.io/acme/app',
                nativeRunnerLabel: 'ubuntu-24.04-arm',
                oidc: $oidc,
            ));

            $this->assertSame('${{ vars.VORTOS_DEPLOY_HOST }}', $stage->env['VORTOS_DEPLOY_HOST'] ?? null);
            $this->assertSame('${{ vars.VORTOS_DEPLOY_USER }}', $stage->env['VORTOS_DEPLOY_USER'] ?? null);
            $this->assertSame('${{ vars.VORTOS_DEPLOY_PORT }}', $stage->env['VORTOS_DEPLOY_PORT'] ?? null);
        }
    }

    public function test_deploy_connection_coordinates_are_not_static_secrets(): void
    {
        // Host/user/port are non-secret coordinates: sourcing them from `secrets.*` would break
        // the OIDC zero-standing-secret posture. They must come from `vars.*`.
        $stage = $this->deployStage($this->withBuild());

        foreach ($stage->env as $value) {
            $this->assertStringNotContainsString('secrets.', $value);
        }
    }

    public function test_deploy_on_runner_stage_also_publishes_connection_env(): void
    {
        // No imageRepository => degenerate on-runner path; still needs the connection env.
        $stage = $this->deployStage(new PipelineDefinition(
            environments: ['production'],
        ));

        $this->assertSame('${{ vars.VORTOS_DEPLOY_HOST }}', $stage->env['VORTOS_DEPLOY_HOST'] ?? null);
        $this->assertSame('${{ vars.VORTOS_DEPLOY_USER }}', $stage->env['VORTOS_DEPLOY_USER'] ?? null);
        $this->assertSame('${{ vars.VORTOS_DEPLOY_PORT }}', $stage->env['VORTOS_DEPLOY_PORT'] ?? null);
    }

    public function test_record_manifest_derives_arch_and_git_sha(): void
    {
        $run = $this->remoteStep($this->withBuild())->run;

        $this->assertStringContainsString('--repository=ghcr.io/acme/app', $run);
        $this->assertStringContainsString('--digest=${{ needs.build.outputs.image }}', $run);
        $this->assertStringContainsString('--git-sha=${{ github.sha }}', $run);
        $this->assertStringContainsString('--arch=linux/arm64', $run);
    }
}
