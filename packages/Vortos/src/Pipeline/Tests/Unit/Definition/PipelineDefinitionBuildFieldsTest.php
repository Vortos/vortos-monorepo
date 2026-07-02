<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\BuildMode;
use Vortos\Release\Manifest\Arch;

final class PipelineDefinitionBuildFieldsTest extends TestCase
{
    public function test_defaults(): void
    {
        $def = new PipelineDefinition();

        $this->assertSame(Arch::Arm64, $def->targetArch);
        $this->assertNull($def->imageRepository);
        $this->assertSame(BuildMode::Native, $def->buildMode);
        $this->assertNull($def->nativeRunnerLabel);
        $this->assertFalse($def->oidc);
        $this->assertNull($def->baseImageDigest);
        $this->assertTrue($def->emitSbom);
        $this->assertSame('Dockerfile', $def->dockerfilePath);
        $this->assertFalse($def->hasBuildStage());
    }

    public function test_oidc_auto_true_when_image_repository_set(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm');

        $this->assertTrue($def->oidc);
        $this->assertTrue($def->hasBuildStage());
    }

    public function test_native_build_stage_requires_runner_label(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('native_runner_label');

        new PipelineDefinition(imageRepository: 'ghcr.io/org/app');
    }

    public function test_non_native_build_stage_does_not_require_runner_label(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', buildMode: BuildMode::BuildxQemu);

        $this->assertNull($def->nativeRunnerLabel);
        $this->assertTrue($def->hasBuildStage());
    }

    public function test_runner_label_without_build_stage_is_allowed(): void
    {
        $def = new PipelineDefinition(nativeRunnerLabel: 'ubuntu-24.04-arm');

        $this->assertSame('ubuntu-24.04-arm', $def->nativeRunnerLabel);
        $this->assertFalse($def->hasBuildStage());
    }

    public function test_oidc_auto_false_when_image_repository_null(): void
    {
        $def = new PipelineDefinition();

        $this->assertFalse($def->oidc);
    }

    public function test_oidc_explicit_override(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: false, nativeRunnerLabel: 'ubuntu-24.04-arm');
        $this->assertFalse($def->oidc);

        $def2 = new PipelineDefinition(imageRepository: null, oidc: true);
        $this->assertTrue($def2->oidc);
    }

    public function test_invalid_image_repository_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid registry reference');

        new PipelineDefinition(imageRepository: 'INVALID REPO!!');
    }

    public function test_valid_image_repositories(): void
    {
        $repos = [
            'ghcr.io/org/app',
            'docker.io/library/nginx',
            'registry.example.com:5000/myapp',
            'myregistry/myimage',
        ];

        foreach ($repos as $repo) {
            $def = new PipelineDefinition(imageRepository: $repo, nativeRunnerLabel: 'ubuntu-24.04-arm');
            $this->assertSame($repo, $def->imageRepository);
        }
    }

    public function test_invalid_base_image_digest_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Base image digest');

        new PipelineDefinition(baseImageDigest: 'not-a-digest');
    }

    public function test_valid_base_image_digest(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $def = new PipelineDefinition(baseImageDigest: $digest);
        $this->assertSame($digest, $def->baseImageDigest);
    }

    public function test_to_array_includes_new_fields(): void
    {
        $def = new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            buildMode: BuildMode::BuildxQemu,
            targetArch: Arch::Amd64,
        );

        $array = $def->toArray();

        $this->assertSame('buildx-qemu', $array['build_mode']);
        $this->assertSame('linux/amd64', $array['target_arch']);
        $this->assertSame('ghcr.io/org/app', $array['image_repository']);
        $this->assertTrue($array['oidc']);
        $this->assertTrue($array['emit_sbom']);
        $this->assertSame('Dockerfile', $array['dockerfile_path']);
        // Non-native build ⇒ no runner label configured ⇒ key omitted.
        $this->assertArrayNotHasKey('native_runner_label', $array);
    }

    public function test_to_array_includes_native_runner_label_when_set(): void
    {
        $def = new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        );

        $this->assertSame('ubuntu-24.04-arm', $def->toArray()['native_runner_label']);
    }

    public function test_to_array_native_runner_label_omitted_when_null(): void
    {
        $def = new PipelineDefinition();

        $this->assertArrayNotHasKey('native_runner_label', $def->toArray());
    }

    public function test_to_array_ksort_stable(): void
    {
        $def1 = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm');
        $def2 = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm');

        $this->assertSame($def1->toArray(), $def2->toArray());

        $keys = array_keys($def1->toArray());
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function test_to_array_base_image_digest_only_when_set(): void
    {
        $def = new PipelineDefinition();
        $this->assertArrayNotHasKey('base_image_digest', $def->toArray());

        $digest = 'sha256:' . str_repeat('b', 64);
        $def2 = new PipelineDefinition(baseImageDigest: $digest);
        $this->assertArrayHasKey('base_image_digest', $def2->toArray());
    }

    public function test_to_array_image_repository_only_when_set(): void
    {
        $def = new PipelineDefinition();
        $this->assertArrayNotHasKey('image_repository', $def->toArray());
    }

    public function test_builder_carries_all_new_fields(): void
    {
        $digest = 'sha256:' . str_repeat('c', 64);

        $def = (new PipelineDefinitionBuilder())
            ->targetArch(Arch::Amd64)
            ->imageRepository('ghcr.io/org/app')
            ->buildMode(BuildMode::BuildxQemu)
            ->nativeRunnerLabel('self-hosted-arm')
            ->oidc(false)
            ->baseImageDigest($digest)
            ->emitSbom(false)
            ->dockerfilePath('docker/Dockerfile.prod')
            ->build();

        $this->assertSame(Arch::Amd64, $def->targetArch);
        $this->assertSame('ghcr.io/org/app', $def->imageRepository);
        $this->assertSame(BuildMode::BuildxQemu, $def->buildMode);
        $this->assertSame('self-hosted-arm', $def->nativeRunnerLabel);
        $this->assertFalse($def->oidc);
        $this->assertSame($digest, $def->baseImageDigest);
        $this->assertFalse($def->emitSbom);
        $this->assertSame('docker/Dockerfile.prod', $def->dockerfilePath);
    }

    public function test_builder_clone_per_setter(): void
    {
        $b1 = new PipelineDefinitionBuilder();
        $b2 = $b1->imageRepository('ghcr.io/org/app')->nativeRunnerLabel('ubuntu-24.04-arm');

        $def1 = $b1->build();
        $def2 = $b2->build();

        $this->assertNull($def1->imageRepository);
        $this->assertSame('ghcr.io/org/app', $def2->imageRepository);
    }
}
