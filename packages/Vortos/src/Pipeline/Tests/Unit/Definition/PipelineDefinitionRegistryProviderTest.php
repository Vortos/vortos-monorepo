<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;

final class PipelineDefinitionRegistryProviderTest extends TestCase
{
    public function test_default_registry_provider_is_ghcr(): void
    {
        $def = new PipelineDefinition();

        $this->assertSame('ghcr', $def->registryProvider);
    }

    public function test_custom_registry_provider_is_preserved(): void
    {
        $def = new PipelineDefinition(registryProvider: 'docker-hub');

        $this->assertSame('docker-hub', $def->registryProvider);
    }

    public function test_gcp_artifact_registry_provider(): void
    {
        $def = new PipelineDefinition(registryProvider: 'gcp-artifact-registry');

        $this->assertSame('gcp-artifact-registry', $def->registryProvider);
    }

    public function test_empty_registry_provider_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Registry provider/');

        new PipelineDefinition(registryProvider: '');
    }

    public function test_registry_provider_in_to_array(): void
    {
        $def = new PipelineDefinition(registryProvider: 'docker-hub');

        $arr = $def->toArray();

        $this->assertArrayHasKey('registry_provider', $arr);
        $this->assertSame('docker-hub', $arr['registry_provider']);
    }

    public function test_registry_provider_in_to_array_defaults(): void
    {
        $def = new PipelineDefinition();

        $arr = $def->toArray();

        $this->assertArrayHasKey('registry_provider', $arr);
        $this->assertSame('ghcr', $arr['registry_provider']);
    }
}
