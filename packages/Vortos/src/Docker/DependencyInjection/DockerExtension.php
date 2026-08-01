<?php
declare(strict_types=1);

namespace Vortos\Docker\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Vortos\Docker\Command\PublishDockerCommand;
use Vortos\Docker\Command\WorkerInstallCommand;
use Vortos\Docker\Command\WorkerListCommand;
use Vortos\Docker\Command\WorkerRemoveCommand;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Docker\Worker\SupervisorFileManager;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class DockerExtension extends Extension
{
    public const WORKER_TAG = 'vortos.worker';

    public function getAlias(): string
    {
        return 'vortos_docker';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(WorkerProcessDefinition::class)
            ->addTag(self::WORKER_TAG);

        $container->register(DockerFilePublisher::class, DockerFilePublisher::class)
            ->setArgument('$stubRoot', __DIR__ . '/../stubs')
            ->setPublic(false);

        $container->register(WorkerProcessRegistry::class, WorkerProcessRegistry::class)
            ->setArgument('$definitions', new TaggedIteratorArgument(self::WORKER_TAG))
            ->setPublic(false);

        $container->register(SupervisorFileManager::class, SupervisorFileManager::class)
            ->setPublic(false);

        // Security owns this parameter; seeded here so the Docker package still publishes when
        // Security is not installed. Whichever extension runs second wins, and Security setting
        // the real allowlist over this empty default is the order we want.
        if (!$container->hasParameter('vortos.security.cors.origins')) {
            $container->setParameter('vortos.security.cors.origins', []);
        }

        $container->register(PublishDockerCommand::class, PublishDockerCommand::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->setArgument('$corsOrigins', '%vortos.security.cors.origins%')
            ->addTag('console.command');

        foreach ([WorkerListCommand::class, WorkerInstallCommand::class, WorkerRemoveCommand::class] as $commandClass) {
            $container->register($commandClass, $commandClass)
                ->setAutowired(true)
                ->setPublic(true)
                ->addTag('console.command');
        }
    }
}
