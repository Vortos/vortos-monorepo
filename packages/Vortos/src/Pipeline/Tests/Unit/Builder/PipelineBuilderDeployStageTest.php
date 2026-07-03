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

    public function test_deploy_records_manifest_before_deploying(): void
    {
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));
        $runs = array_map(static fn (CommandStep $s): string => $s->run, $steps);

        $recordIdx = null;
        $deployIdx = null;
        foreach ($runs as $i => $run) {
            if (str_contains($run, 'vortos:release:record-manifest')) {
                $recordIdx = $i;
            }
            if (str_contains($run, ' deploy --env=')) {
                $deployIdx = $i;
            }
        }

        $this->assertNotNull($recordIdx, 'record-manifest step must be emitted (closes blocker E)');
        $this->assertNotNull($deployIdx, 'deploy step must be emitted');
        $this->assertLessThan($deployIdx, $recordIdx, 'record-manifest must run before deploy');
    }

    public function test_deploy_in_image_uses_docker_run_with_pinned_reference(): void
    {
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));

        foreach ($steps as $step) {
            $this->assertStringContainsString('docker run --rm', $step->run);
            $this->assertStringContainsString('ghcr.io/acme/app@${{ needs.build.outputs.image }}', $step->run);
        }
    }

    public function test_deploy_passes_image_repository_and_digest(): void
    {
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));
        $deploy = null;
        foreach ($steps as $s) {
            if (str_contains($s->run, ' deploy --env=')) {
                $deploy = $s;
            }
        }

        self::assertNotNull($deploy);
        $this->assertStringContainsString('--image-repository=ghcr.io/acme/app', $deploy->run);
        $this->assertStringContainsString('--image-digest=${{ needs.build.outputs.image }}', $deploy->run);
    }

    public function test_oidc_default_deploy_references_no_standing_secret(): void
    {
        // Default posture (oidc true): zero standing secrets — no age KEK, no mounted store.
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));

        foreach ($steps as $step) {
            $this->assertArrayNotHasKey('VORTOS_AGE_IDENTITY', $step->env);
            $this->assertStringNotContainsString('VORTOS_AGE_IDENTITY', $step->run);
            $this->assertStringNotContainsString('vortos-secrets.age', $step->run);
        }
    }

    public function test_ssh_key_posture_exposes_age_kek_and_mounts_store(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        );

        $steps = $this->commandSteps($this->deployStage($definition));

        foreach ($steps as $step) {
            $this->assertSame(
                '${{ secrets.VORTOS_AGE_IDENTITY }}',
                $step->env['VORTOS_AGE_IDENTITY'] ?? null,
                'ssh-key posture opens the encrypted store with the age KEK',
            );
            $this->assertStringContainsString('-v "$PWD/vortos-secrets.age:/app/vortos-secrets.age:ro"', $step->run);
        }
    }

    public function test_ssh_key_posture_pins_secrets_store_path_to_mount_target(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        );

        $steps = $this->commandSteps($this->deployStage($definition));

        foreach ($steps as $step) {
            // The store path forwarded to the container must equal the mount target, otherwise
            // the app resolves the WORKDIR-relative default and never sees the mounted file.
            $this->assertStringContainsString(
                '-e VORTOS_SECRETS_STORE_PATH=/app/vortos-secrets.age',
                $step->run,
                'ssh-key posture must pin the in-container secrets store path to the mount target',
            );
        }
    }

    public function test_oidc_posture_does_not_pin_secrets_store_path(): void
    {
        // OIDC posture mounts no store, so it must not reference a store path either.
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));

        foreach ($steps as $step) {
            $this->assertStringNotContainsString('VORTOS_SECRETS_STORE_PATH', $step->run);
        }
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
        $steps = $this->commandSteps($this->deployStage($this->withBuild()));
        $record = null;
        foreach ($steps as $s) {
            if (str_contains($s->run, 'record-manifest')) {
                $record = $s;
            }
        }

        self::assertNotNull($record);
        $this->assertStringContainsString('--repository=ghcr.io/acme/app', $record->run);
        $this->assertStringContainsString('--digest=${{ needs.build.outputs.image }}', $record->run);
        $this->assertStringContainsString('--git-sha=${{ github.sha }}', $record->run);
        $this->assertStringContainsString('--arch=linux/arm64', $record->run);
    }
}
