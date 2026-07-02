<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Registry\RegistryLoginContext;

final class RegistryLoginContextTest extends TestCase
{
    public function test_registry_host_returns_first_path_segment(): void
    {
        $ctx = new RegistryLoginContext(new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));

        $this->assertSame('ghcr.io', $ctx->registryHost());
    }

    public function test_registry_host_for_docker_io(): void
    {
        $ctx = new RegistryLoginContext(new PipelineDefinition(
            imageRepository: 'docker.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));

        $this->assertSame('docker.io', $ctx->registryHost());
    }

    public function test_registry_host_for_gcp_artifact_registry(): void
    {
        $ctx = new RegistryLoginContext(new PipelineDefinition(
            imageRepository: 'europe-west4-docker.pkg.dev/proj/repo/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        ));

        $this->assertSame('europe-west4-docker.pkg.dev', $ctx->registryHost());
    }

    public function test_registry_host_empty_when_no_image_repository(): void
    {
        $ctx = new RegistryLoginContext(new PipelineDefinition());

        $this->assertSame('', $ctx->registryHost());
    }

    public function test_definition_is_accessible(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', nativeRunnerLabel: 'ubuntu-24.04-arm');
        $ctx = new RegistryLoginContext($def);

        $this->assertSame($def, $ctx->definition);
    }
}
