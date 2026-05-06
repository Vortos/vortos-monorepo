<?php
declare(strict_types=1);

namespace Vortos\Docker\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\Docker\Command\PublishDockerCommand;
use Vortos\Docker\Service\DockerFilePublisher;

final class DockerExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_docker';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(DockerFilePublisher::class, DockerFilePublisher::class)
            ->setArgument('$stubRoot', __DIR__ . '/../stubs')
            ->setPublic(false);

        $container->register(PublishDockerCommand::class, PublishDockerCommand::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('console.command');
    }
}
