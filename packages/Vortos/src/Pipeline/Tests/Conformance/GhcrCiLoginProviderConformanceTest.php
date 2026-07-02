<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Conformance;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Testing\CiRegistryLoginProviderConformanceTestCase;

final class GhcrCiLoginProviderConformanceTest extends CiRegistryLoginProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'ghcr';
    }

    protected function createProvider(): CiRegistryLoginProviderInterface
    {
        return new GhcrCiLoginProvider();
    }

    protected function createDefinition(): PipelineDefinition
    {
        return new PipelineDefinition(
            imageRepository: 'ghcr.io/org/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            registryProvider: 'ghcr',
        );
    }
}
