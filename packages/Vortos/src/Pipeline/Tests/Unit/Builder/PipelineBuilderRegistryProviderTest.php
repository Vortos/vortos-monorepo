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
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

final class PipelineBuilderRegistryProviderTest extends TestCase
{
    private static function makeRegistry(): CiRegistryLoginProviderRegistry
    {
        return new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));
    }

    private function buildStage(PipelineDefinition $definition): \Vortos\Pipeline\Model\Stage
    {
        $pipeline = (new PipelineBuilder(new StageGate(), self::makeRegistry()))->build($definition);

        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Build) {
                return $stage;
            }
        }

        $this->fail('Build stage was not emitted');
    }

    public function test_ghcr_login_step_uses_ghcr_io(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'ghcr',
        ));

        $loginStep = $this->findLoginStep($stage);
        $this->assertNotNull($loginStep);
        $this->assertSame('ghcr.io', $loginStep->with['registry'] ?? null);
    }

    public function test_docker_hub_login_step_uses_docker_io(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'docker.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'docker-hub',
        ));

        $loginStep = $this->findLoginStep($stage);
        $this->assertNotNull($loginStep);
        $this->assertSame('docker.io', $loginStep->with['registry'] ?? null);
    }

    public function test_gcp_login_step_uses_registry_host_from_image(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'europe-west4-docker.pkg.dev/proj/repo/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'gcp-artifact-registry',
        ));

        $loginStep = $this->findLoginStep($stage);
        $this->assertNotNull($loginStep);
        $this->assertSame('europe-west4-docker.pkg.dev', $loginStep->with['registry'] ?? null);
    }

    public function test_ghcr_build_stage_has_packages_write(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'ghcr',
        ));

        $perms = $stage->permissions->toArray();
        $this->assertSame('write', $perms['packages'] ?? null);
    }

    public function test_docker_hub_build_stage_has_no_packages_write(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'docker.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'docker-hub',
        ));

        $perms = $stage->permissions->toArray();
        $this->assertArrayNotHasKey('packages', $perms);
    }

    public function test_gcp_build_stage_has_no_packages_write(): void
    {
        $stage = $this->buildStage(new PipelineDefinition(
            imageRepository: 'europe-west4-docker.pkg.dev/proj/repo/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'gcp-artifact-registry',
        ));

        $perms = $stage->permissions->toArray();
        $this->assertArrayNotHasKey('packages', $perms);
    }

    public function test_build_stage_always_has_contents_read(): void
    {
        foreach (['ghcr', 'docker-hub', 'gcp-artifact-registry'] as $provider) {
            $stage = $this->buildStage(new PipelineDefinition(
                imageRepository: 'ghcr.io/org/app',
                nativeRunnerLabel: 'ubuntu-24.04-arm',
                registryProvider: $provider === 'ghcr' ? 'ghcr' : $provider,
            ));

            $perms = $stage->permissions->toArray();
            $this->assertSame('read', $perms['contents'] ?? null, "contents:read missing for $provider");
        }
    }

    public function test_missing_login_providers_throws_logic_exception(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/CiRegistryLoginProviderRegistry/');

        $builder->build($def);
    }

    private function findLoginStep(\Vortos\Pipeline\Model\Stage $stage): ?ActionStep
    {
        foreach ($stage->steps as $step) {
            if ($step instanceof ActionStep && $step->action->repo === 'login-action') {
                return $step;
            }
        }

        return null;
    }
}
