<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Driver\Registry\DockerHubCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GcpArtifactRegistryCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;
use Vortos\Release\Manifest\Arch;

final class BuildStageContractTest extends TestCase
{
    private static function makeLoginProviderRegistry(): CiRegistryLoginProviderRegistry
    {
        return new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));
    }

    private function emitYaml(PipelineDefinition $def): string
    {
        $builder = new PipelineBuilder(new StageGate(), self::makeLoginProviderRegistry());
        $pipeline = $builder->build($def);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $def,
        );

        $artifacts = $emitter->emit($pipeline);
        $ci = null;
        foreach ($artifacts as $a) {
            if (str_contains($a->relativePath, 'ci.yml')) {
                $ci = $a;
                break;
            }
        }

        $this->assertNotNull($ci, 'ci.yml must be emitted');
        return $ci->contents;
    }

    private function emitArray(PipelineDefinition $def): array
    {
        $builder = new PipelineBuilder(new StageGate(), self::makeLoginProviderRegistry());
        $pipeline = $builder->build($def);

        $mapper = new GitHubWorkflowMapper();
        return $mapper->map($pipeline);
    }

    public function test_all_uses_references_are_sha_pinned_with_build(): void
    {
        $yaml = $this->emitYaml(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        preg_match_all('/uses:\s*(.+)/', $yaml, $matches);
        foreach ($matches[1] as $uses) {
            $clean = trim($uses, " \t\n\r\0\x0B'\"");
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_.-]+\/[a-z0-9_.-]+@[0-9a-f]{40}\s*#\s*\S+$/i',
                $clean,
                sprintf('Action reference "%s" is not SHA-pinned', $clean),
            );
        }
    }

    public function test_build_job_permissions_least_privilege(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: true));

        $this->assertArrayHasKey('build', $array['jobs']);
        $buildPerms = $array['jobs']['build']['permissions'];

        $this->assertSame('read', $buildPerms['contents']);
        $this->assertSame('write', $buildPerms['packages']);
        $this->assertSame('write', $buildPerms['id-token']);
        $this->assertArrayNotHasKey('actions', $buildPerms);
    }

    public function test_deploy_job_references_build_output(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        $this->assertArrayHasKey('deploy', $array['jobs']);
        $this->assertContains('build', $array['jobs']['deploy']['needs']);

        $deploySteps = $array['jobs']['deploy']['steps'];
        $hasDigestRef = false;
        foreach ($deploySteps as $step) {
            if (isset($step['run']) && str_contains($step['run'], 'needs.build.outputs.image')) {
                $hasDigestRef = true;
                break;
            }
        }
        $this->assertTrue($hasDigestRef, 'Deploy must reference build digest output');
    }

    public function test_deploy_never_uses_mutable_tag_for_image(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        $deploySteps = $array['jobs']['deploy']['steps'];
        foreach ($deploySteps as $step) {
            if (isset($step['run']) && str_contains($step['run'], 'deploy --env')) {
                $this->assertStringNotContainsString('ghcr.io/org/app:latest', $step['run']);
                $this->assertStringNotContainsString('ghcr.io/org/app:main', $step['run']);
                $this->assertStringContainsString('--image-digest', $step['run']);
            }
        }
    }

    public function test_arch_check_step_in_build_job(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        $buildSteps = $array['jobs']['build']['steps'];
        $hasArchCheck = false;
        foreach ($buildSteps as $step) {
            if (isset($step['run']) && str_contains($step['run'], 'docker manifest inspect')) {
                $hasArchCheck = true;
                $this->assertStringContainsString('exit 1', $step['run']);
                break;
            }
        }
        $this->assertTrue($hasArchCheck, 'Build job must have arch assertion step');
    }

    public function test_build_job_has_outputs(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        $this->assertArrayHasKey('outputs', $array['jobs']['build']);
        $this->assertArrayHasKey('image', $array['jobs']['build']['outputs']);
    }

    public function test_no_standing_secret_with_oidc_on(): void
    {
        $yaml = $this->emitYaml(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: true));

        $this->assertStringNotContainsString('secrets.DOCKER_PASSWORD', $yaml);
        $this->assertStringNotContainsString('secrets.REGISTRY_PAT', $yaml);
        $this->assertStringNotContainsString('secrets.SSH_KEY', $yaml);

        $this->assertStringContainsString('id-token', $yaml);
    }

    public function test_back_compat_no_build_when_image_repository_null(): void
    {
        $array = $this->emitArray(new PipelineDefinition());

        $this->assertArrayNotHasKey('build', $array['jobs']);
        $this->assertArrayHasKey('deploy', $array['jobs']);
        $this->assertNotContains('build', $array['jobs']['deploy']['needs'] ?? []);
    }

    public function test_sbom_provenance_hooks_present(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', emitSbom: true));

        $buildSteps = $array['jobs']['build']['steps'];
        $hasSbom = false;
        $hasProvenance = false;
        foreach ($buildSteps as $step) {
            if (isset($step['uses']) && str_contains($step['uses'], 'sbom-action')) {
                $hasSbom = true;
            }
            if (isset($step['with']['provenance']) && $step['with']['provenance'] === 'true') {
                $hasProvenance = true;
            }
        }
        $this->assertTrue($hasSbom, 'SBOM step must be present');
        $this->assertTrue($hasProvenance, 'Provenance must be enabled on build step');
    }

    public function test_semver_tag_step_on_tag_push_only(): void
    {
        $array = $this->emitArray(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));

        $buildSteps = $array['jobs']['build']['steps'];
        $hasTagStep = false;
        foreach ($buildSteps as $step) {
            if (isset($step['run']) && str_contains($step['run'], 'imagetools create')) {
                $hasTagStep = true;
                $this->assertArrayHasKey('if', $step);
                $this->assertStringContainsString('tag', $step['if']);
            }
        }
        $this->assertTrue($hasTagStep, 'Semver tag step must be present');
    }

    public function test_buildx_qemu_mode_has_qemu_step(): void
    {
        $array = $this->emitArray(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::BuildxQemu,
        ));

        $buildSteps = $array['jobs']['build']['steps'];
        $hasQemu = false;
        foreach ($buildSteps as $step) {
            if (isset($step['uses']) && str_contains($step['uses'], 'setup-qemu-action')) {
                $hasQemu = true;
                break;
            }
        }
        $this->assertTrue($hasQemu, 'QEMU setup must be present in buildx-qemu mode');
        $this->assertSame('ubuntu-latest', $array['jobs']['build']['runs-on']);
    }

    public function test_native_mode_no_qemu(): void
    {
        $array = $this->emitArray(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::Native,
        ));

        $buildSteps = $array['jobs']['build']['steps'];
        foreach ($buildSteps as $step) {
            if (isset($step['uses'])) {
                $this->assertStringNotContainsString('setup-qemu', $step['uses']);
            }
        }
    }
}
