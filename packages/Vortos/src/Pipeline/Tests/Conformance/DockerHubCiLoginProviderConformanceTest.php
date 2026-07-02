<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Conformance;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\DockerHubCiLoginProvider;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Testing\CiRegistryLoginProviderConformanceTestCase;

final class DockerHubCiLoginProviderConformanceTest extends CiRegistryLoginProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'docker-hub';
    }

    protected function createProvider(): CiRegistryLoginProviderInterface
    {
        return new DockerHubCiLoginProvider();
    }

    protected function createDefinition(): PipelineDefinition
    {
        return new PipelineDefinition(
            imageRepository: 'docker.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'docker-hub',
        );
    }
}
