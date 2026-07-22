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
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;
use Vortos\Release\Manifest\Arch;

final class PipelineBuilderBuildStageTest extends TestCase
{
    private static function makeLoginProviderRegistry(): CiRegistryLoginProviderRegistry
    {
        return new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));
    }

    private function buildPipeline(PipelineDefinition $definition): \Vortos\Pipeline\Model\Pipeline
    {
        return (new PipelineBuilder(new StageGate(), self::makeLoginProviderRegistry()))->build($definition);
    }

    private function findStage(\Vortos\Pipeline\Model\Pipeline $pipeline, StageKind $kind): ?\Vortos\Pipeline\Model\Stage
    {
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === $kind) {
                return $stage;
            }
        }
        return null;
    }

    public function test_no_build_stage_without_image_repository(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition());
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNull($build);
    }

    public function test_build_stage_emitted_with_image_repository(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $this->assertSame('build', $build->id);
        $this->assertSame(StageKind::Build, $build->kind);
    }

    public function test_build_stage_has_image_output(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $this->assertArrayHasKey('image', $build->outputs);
        $this->assertStringContainsString('steps.image.outputs.digest', $build->outputs['image']);
    }

    public function test_build_stage_needs_test_analyse_agnosticism(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $this->assertContains('tests', $build->needs);
        $this->assertContains('analyse', $build->needs);
        $this->assertContains('agnosticism', $build->needs);
    }

    public function test_deploy_needs_build_when_image_repository_set(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));
        $deploy = $this->findStage($pipeline, StageKind::Deploy);

        $this->assertNotNull($deploy);
        $this->assertContains('build', $deploy->needs);
    }

    public function test_deploy_passes_image_digest_when_build_present(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));
        $deploy = $this->findStage($pipeline, StageKind::Deploy);

        $this->assertNotNull($deploy);
        $deployCmds = array_filter($deploy->steps, fn ($s) => $s instanceof CommandStep && str_contains($s->run, 'deploy --env'));
        $deployCmd = array_values($deployCmds)[0] ?? null;

        $this->assertNotNull($deployCmd);
        $this->assertStringContainsString('--image-digest=${{ needs.build.outputs.image }}', $deployCmd->run);
    }

    public function test_deploy_does_not_pass_image_digest_without_build(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition());
        $deploy = $this->findStage($pipeline, StageKind::Deploy);

        $this->assertNotNull($deploy);
        $deployCmds = array_filter($deploy->steps, fn ($s) => $s instanceof CommandStep && str_contains($s->run, 'deploy --env'));
        $deployCmd = array_values($deployCmds)[0] ?? null;

        $this->assertNotNull($deployCmd);
        $this->assertStringNotContainsString('--image-digest', $deployCmd->run);
    }

    public function test_deploy_does_not_need_build_without_image_repository(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition());
        $deploy = $this->findStage($pipeline, StageKind::Deploy);

        $this->assertNotNull($deploy);
        $this->assertNotContains('build', $deploy->needs);
    }

    public function test_native_mode_uses_native_runner_label(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::Native,
            nativeRunnerLabel: 'self-hosted-arm64',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $this->assertSame('self-hosted-arm64', $build->runner->label);
    }

    public function test_native_mode_has_no_qemu_step(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            buildMode: BuildMode::Native,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep) {
                $this->assertStringNotContainsString('qemu', strtolower($step->name));
            }
        }
    }

    public function test_qemu_mode_uses_ubuntu_latest(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::BuildxQemu,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $this->assertSame('ubuntu-latest', $build->runner->label);
    }

    public function test_qemu_mode_has_qemu_step(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::BuildxQemu,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $hasQemu = false;
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && str_contains(strtolower($step->name), 'qemu')) {
                $hasQemu = true;
                break;
            }
        }
        $this->assertTrue($hasQemu, 'QEMU step must be present in buildx-qemu mode');
    }

    public function test_oidc_on_adds_id_token_permission(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: true,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $perms = $build->permissions->toArray();
        $this->assertArrayHasKey('id-token', $perms);
        $this->assertSame('write', $perms['id-token']);
    }

    public function test_oidc_off_no_id_token_permission(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $perms = $build->permissions->toArray();
        $this->assertArrayNotHasKey('id-token', $perms);
    }

    public function test_base_image_digest_present_adds_build_arg(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            baseImageDigest: $digest,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $buildStep = null;
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && $step->id === 'build') {
                $buildStep = $step;
                break;
            }
        }
        $this->assertNotNull($buildStep);
        $this->assertArrayHasKey('build-args', $buildStep->with);
        $this->assertStringContainsString($digest, $buildStep->with['build-args']);
    }

    public function test_base_image_digest_null_auto_resolves_at_build_time(): void
    {
        // R7-5: null digest now emits a resolver step + env-backed build-arg (was a bare warning).
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        $hasResolver = false;
        $buildStep = null;
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep && $step->id === 'basedigest') {
                $hasResolver = true;
            }
            if ($step instanceof ActionStep && $step->id === 'build') {
                $buildStep = $step;
            }
        }

        $this->assertTrue($hasResolver, 'A base-image-digest resolver step must be present when the digest is null.');
        $this->assertNotNull($buildStep);
        $this->assertArrayHasKey('build-args', $buildStep->with);
        $this->assertStringContainsString('BASE_IMAGE_DIGEST=${{ env.BASE_IMAGE_DIGEST }}', $buildStep->with['build-args']);
    }

    public function test_base_image_digest_set_no_resolver_step(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            baseImageDigest: $digest,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep) {
                $this->assertNotSame('basedigest', $step->id, 'No resolver step when the digest is explicitly pinned.');
            }
        }
    }

    public function test_arch_check_step_present(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $archCheck = null;
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep && $step->id === 'archcheck') {
                $archCheck = $step;
                break;
            }
        }
        $this->assertNotNull($archCheck);
        $this->assertStringContainsString('docker manifest inspect', $archCheck->run);
        $this->assertStringContainsString('exit 1', $archCheck->run);
    }

    public function test_build_stage_has_expose_digest_step(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $imageStep = null;
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep && $step->id === 'image') {
                $imageStep = $step;
                break;
            }
        }
        $this->assertNotNull($imageStep);
        $this->assertStringContainsString('GITHUB_OUTPUT', $imageStep->run);
    }

    public function test_sbom_step_emitted_when_enabled(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitSbom: true,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $hasSbom = false;
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && str_contains(strtolower($step->name), 'sbom')) {
                $hasSbom = true;
                break;
            }
        }
        $this->assertTrue($hasSbom);
    }

    public function test_cve_scan_gate_emitted_when_enabled(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitScanGate: true,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        $scan = null;
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && $step->action->repo === 'trivy-action') {
                $scan = $step;
                break;
            }
        }
        $this->assertNotNull($scan, 'CVE scan gate step should be present when emitScanGate is on.');
        // Fail-closed gate: non-zero exit on findings, scanning the exact pushed digest.
        $this->assertSame('1', $scan->with['exit-code'] ?? null);
        $this->assertSame('HIGH,CRITICAL', $scan->with['severity'] ?? null);
        $this->assertStringContainsString('@${{ steps.build.outputs.digest }}', $scan->with['image-ref'] ?? '');
    }

    public function test_cve_scan_gate_omitted_when_disabled(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitScanGate: false,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep) {
                $this->assertNotSame('trivy-action', $step->action->repo);
            }
        }
    }

    public function test_sign_and_verify_steps_emitted_when_enabled(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitSign: true,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        $hasInstaller = false;
        $sign = null;
        $verify = null;
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && $step->action->repo === 'cosign-installer') {
                $hasInstaller = true;
            }
            if ($step instanceof CommandStep && $step->id === 'sign') {
                $sign = $step;
            }
            if ($step instanceof CommandStep && $step->id === 'verify') {
                $verify = $step;
            }
        }
        $this->assertTrue($hasInstaller, 'Cosign installer step should be present when emitSign is on.');
        $this->assertNotNull($sign);
        $this->assertNotNull($verify);
        $this->assertStringContainsString('cosign sign --yes', $sign->run);
        $this->assertStringContainsString('cosign verify', $verify->run);
        // Keyless trust anchor: verify pins BOTH the certificate identity (this repo/workflow) and the
        // GitHub OIDC issuer, so an image signed by any other identity fails the gate.
        $this->assertStringContainsString('--certificate-identity-regexp', $verify->run);
        $this->assertStringContainsString('--certificate-oidc-issuer', $verify->run);
        $this->assertStringContainsString('token.actions.githubusercontent.com', $verify->run);
    }

    public function test_sign_enables_id_token_permission_without_oidc(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
            emitSign: true,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        // Keyless Fulcio signing needs id-token:write even though the deploy posture is not OIDC.
        $perms = $build->permissions->toArray();
        $this->assertSame('write', $perms['id-token'] ?? null);
    }

    public function test_sign_and_scan_omitted_by_default(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);
        $this->assertNotNull($build);

        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep) {
                $this->assertNotSame('sign', $step->id);
                $this->assertNotSame('verify', $step->id);
            }
            if ($step instanceof ActionStep) {
                $this->assertNotSame('cosign-installer', $step->action->repo);
                $this->assertNotSame('trivy-action', $step->action->repo);
            }
        }
    }

    public function test_sbom_step_omitted_when_disabled(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitSbom: false,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        foreach ($build->steps as $step) {
            if ($step instanceof ActionStep && str_contains(strtolower($step->name), 'sbom')) {
                $this->fail('SBOM step should not be present when emitSbom is false');
            }
        }
    }

    public function test_semver_tag_step_has_tag_condition(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $tagStep = null;
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep && str_contains($step->name, 'release version')) {
                $tagStep = $step;
                break;
            }
        }
        $this->assertNotNull($tagStep);
        $this->assertNotNull($tagStep->condition);
        $this->assertStringContainsString('tag', $tagStep->condition);
    }

    public function test_build_stage_has_packages_write_permission(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $perms = $build->permissions->toArray();
        $this->assertSame('write', $perms['packages']);
        $this->assertSame('read', $perms['contents']);
    }

    public function test_back_compat_no_build_stage_produces_identical_stages(): void
    {
        $withoutBuild = (new PipelineBuilder(new StageGate(), self::makeLoginProviderRegistry()))->build(new PipelineDefinition());
        $stageIds = array_map(fn ($s) => $s->id, $withoutBuild->stages);

        $this->assertContains('tests', $stageIds);
        $this->assertContains('analyse', $stageIds);
        $this->assertContains('agnosticism', $stageIds);
        $this->assertContains('deploy', $stageIds);
        $this->assertNotContains('build', $stageIds);
    }

    public function test_build_ordered_before_deploy(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm'));

        $ids = array_map(fn ($s) => $s->id, $pipeline->stages);
        $buildIdx = array_search('build', $ids, true);
        $deployIdx = array_search('deploy', $ids, true);

        $this->assertNotFalse($buildIdx);
        $this->assertNotFalse($deployIdx);
        $this->assertLessThan($deployIdx, $buildIdx);
    }

    public function test_amd64_arch(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            targetArch: Arch::Amd64,
        ));
        $build = $this->findStage($pipeline, StageKind::Build);

        $this->assertNotNull($build);
        $archCheck = null;
        foreach ($build->steps as $step) {
            if ($step instanceof CommandStep && $step->id === 'archcheck') {
                $archCheck = $step;
                break;
            }
        }
        $this->assertNotNull($archCheck);
        $this->assertStringContainsString('amd64', $archCheck->run);
    }

    public function test_multiple_environments_with_build_stage(): void
    {
        $pipeline = $this->buildPipeline(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            environments: ['staging', 'production'],
        ));
        $deploy = $this->findStage($pipeline, StageKind::Deploy);

        $this->assertNotNull($deploy);
        $this->assertNotNull($deploy->matrix);
        $this->assertCount(2, $deploy->matrix->values);
        $this->assertContains('build', $deploy->needs);
    }
}
