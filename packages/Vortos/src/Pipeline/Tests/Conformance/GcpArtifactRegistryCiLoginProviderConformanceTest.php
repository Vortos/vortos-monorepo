<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Conformance;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\GcpArtifactRegistryCiLoginProvider;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Testing\CiRegistryLoginProviderConformanceTestCase;

final class GcpArtifactRegistryCiLoginProviderConformanceTest extends CiRegistryLoginProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'gcp-artifact-registry';
    }

    protected function createProvider(): CiRegistryLoginProviderInterface
    {
        return new GcpArtifactRegistryCiLoginProvider();
    }

    protected function createDefinition(): PipelineDefinition
    {
        return new PipelineDefinition(
            imageRepository: 'europe-west4-docker.pkg.dev/proj/repo/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'gcp-artifact-registry',
        );
    }
}
