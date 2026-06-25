<?php

declare(strict_types=1);

namespace Vortos\Pipeline\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Console\PipelineGenerateCommand;
use Vortos\Pipeline\Console\PipelineVerifyCommand;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\DependencyInjection\Compiler\CollectCiRegistryLoginProvidersPass;
use Vortos\Pipeline\DependencyInjection\Compiler\CollectPipelineEmittersPass;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Driver\Registry\DockerHubCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GcpArtifactRegistryCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Emitter\PipelineEmitterInterface;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

final class PipelineExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_pipeline';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerEmitterSeam($container);
        $this->registerCiLoginProviderSeam($container);
        $this->registerDefaultDriver($container);
        $this->registerBuilder($container);
        $this->registerCommands($container);

        $container->registerForAutoconfiguration(PipelineEmitterInterface::class)
            ->addTag(CollectPipelineEmittersPass::TAG);

        $container->registerForAutoconfiguration(CiRegistryLoginProviderInterface::class)
            ->addTag(CollectCiRegistryLoginProvidersPass::TAG);
    }

    private function registerCiLoginProviderSeam(ContainerBuilder $container): void
    {
        $container->register(CollectCiRegistryLoginProvidersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(CiRegistryLoginProviderRegistry::class, CiRegistryLoginProviderRegistry::class)
            ->setArgument('$drivers', new Reference(CollectCiRegistryLoginProvidersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(GhcrCiLoginProvider::class, GhcrCiLoginProvider::class)
            ->addTag(CollectCiRegistryLoginProvidersPass::TAG, ['key' => 'ghcr'])
            ->setPublic(false);

        $container->register(DockerHubCiLoginProvider::class, DockerHubCiLoginProvider::class)
            ->addTag(CollectCiRegistryLoginProvidersPass::TAG, ['key' => 'docker-hub'])
            ->setPublic(false);

        $container->register(GcpArtifactRegistryCiLoginProvider::class, GcpArtifactRegistryCiLoginProvider::class)
            ->addTag(CollectCiRegistryLoginProvidersPass::TAG, ['key' => 'gcp-artifact-registry'])
            ->setPublic(false);
    }

    private function registerEmitterSeam(ContainerBuilder $container): void
    {
        $container->register(CollectPipelineEmittersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(PipelineEmitterRegistry::class, PipelineEmitterRegistry::class)
            ->setArgument('$drivers', new Reference(CollectPipelineEmittersPass::LOCATOR_ID))
            ->setPublic(false);
    }

    private function registerDefaultDriver(ContainerBuilder $container): void
    {
        $container->register(PipelineDefinition::class, PipelineDefinition::class)
            ->setPublic(false);

        $container->register(WorkflowYamlWriter::class, WorkflowYamlWriter::class)
            ->setPublic(false);

        $container->register(GitHubWorkflowMapper::class, GitHubWorkflowMapper::class)
            ->setPublic(false);

        $container->register(SplitWorkflowGenerator::class, SplitWorkflowGenerator::class)
            ->setPublic(false);

        $container->register(GitHubActionsEmitter::class, GitHubActionsEmitter::class)
            ->setArgument('$mapper', new Reference(GitHubWorkflowMapper::class))
            ->setArgument('$splitGenerator', new Reference(SplitWorkflowGenerator::class))
            ->setArgument('$yamlWriter', new Reference(WorkflowYamlWriter::class))
            ->setArgument('$definition', new Reference(PipelineDefinition::class))
            ->addTag(CollectPipelineEmittersPass::TAG)
            ->setPublic(false);
    }

    private function registerBuilder(ContainerBuilder $container): void
    {
        $container->register(StageGate::class, StageGate::class)
            ->setPublic(false);

        $container->register(PipelineBuilder::class, PipelineBuilder::class)
            ->setArgument('$gate', new Reference(StageGate::class))
            ->setArgument('$loginProviders', new Reference(CiRegistryLoginProviderRegistry::class))
            ->setPublic(false);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir') ?? '%kernel.project_dir%';

        $container->register(PipelineGenerateCommand::class, PipelineGenerateCommand::class)
            ->setArgument('$registry', new Reference(PipelineEmitterRegistry::class))
            ->setArgument('$builder', new Reference(PipelineBuilder::class))
            ->setArgument('$gate', new Reference(StageGate::class))
            ->setArgument('$splitPackages', [])
            ->setArgument('$projectDir', (string) $projectDir)
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(PipelineVerifyCommand::class, PipelineVerifyCommand::class)
            ->setArgument('$registry', new Reference(PipelineEmitterRegistry::class))
            ->setArgument('$builder', new Reference(PipelineBuilder::class))
            ->setArgument('$splitPackages', [])
            ->setArgument('$projectDir', (string) $projectDir)
            ->setPublic(true)
            ->addTag('console.command');
    }
}
