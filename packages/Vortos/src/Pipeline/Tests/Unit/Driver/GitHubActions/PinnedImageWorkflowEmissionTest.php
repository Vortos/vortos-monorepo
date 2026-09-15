<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Yaml\Yaml;
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
use Vortos\Pipeline\Emitter\EmittedArtifactSet;
use Vortos\Pipeline\Model\AuxiliaryImageName;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\PinnedImage;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/** RC-5: pinned images build in their own workflow, on demand, and never ride a release. */
final class PinnedImageWorkflowEmissionTest extends TestCase
{
    public function test_pinned_images_get_their_own_on_demand_workflow_and_stay_out_of_the_release(): void
    {
        $set = $this->emit(pinned: true);

        $images = $set->byPath('.github/workflows/images.yml');
        self::assertNotNull($images);
        $workflow = Yaml::parse($images->contents);
        self::assertSame('Pinned Images', $workflow['name']);
        self::assertArrayHasKey('workflow_dispatch', $workflow['on']);
        self::assertArrayNotHasKey('branches', $workflow['on']['push'], 'the first digest must be buildable before the release that runs it exists');
        self::assertSame(['docker/postgres/Dockerfile', 'docker/postgres/**'], $workflow['on']['push']['paths']);
        self::assertSame(['pinned-postgres'], array_keys($workflow['jobs']));
        self::assertFalse($workflow['concurrency']['cancel-in-progress'], 'a half-signed image build must not be cancelled by the next push');

        $deploy = Yaml::parse((string) $set->byPath('.github/workflows/deploy.yml')?->contents);
        self::assertArrayNotHasKey('pinned-postgres', $deploy['jobs'], 'building a datastore image is never part of a release');
    }

    public function test_no_images_workflow_without_pinned_images(): void
    {
        self::assertNull($this->emit(pinned: false)->byPath('.github/workflows/images.yml'));
    }

    private function emit(bool $pinned): EmittedArtifactSet
    {
        $definition = new PipelineDefinition(
            imageRepository: 'docker.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
            registryProvider: 'docker-hub',
            syncComposeTopology: true,
            syncComposeTopologyApply: true,
            emitScanGate: true,
            emitSign: true,
            verifySignatureBeforeRelease: true,
            workflowFilename: 'deploy.yml',
            pinnedImages: $pinned ? [new PinnedImage(
                new AuxiliaryImageName('postgres'),
                new ProjectPath('docker/postgres/Dockerfile'),
                new ProjectPath('docker/postgres'),
                new ComposeServiceSet(['write_db']),
            )] : [],
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));
        $pipeline = (new PipelineBuilder(new StageGate(), $registry))->build($definition);

        return (new GitHubActionsEmitter(new GitHubWorkflowMapper(), new SplitWorkflowGenerator(), new WorkflowYamlWriter(), $definition))->emit($pipeline);
    }
}
