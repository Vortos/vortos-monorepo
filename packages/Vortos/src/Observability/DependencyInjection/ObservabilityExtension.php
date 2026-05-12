<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Observability\Command\ListObservabilityStacksCommand;
use Vortos\Observability\Command\PublishObservabilityTemplatesCommand;
use Vortos\Observability\Service\ObservabilityTemplatePublisher;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;

final class ObservabilityExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_observability';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(ObservabilityTemplateRegistry::class, ObservabilityTemplateRegistry::class)
            ->setArgument('$root', __DIR__ . '/../Resources/observability')
            ->setPublic(false);

        $container->register(ObservabilityTemplatePublisher::class, ObservabilityTemplatePublisher::class)
            ->setArgument('$registry', new Reference(ObservabilityTemplateRegistry::class))
            ->setPublic(false);

        $container->register(ListObservabilityStacksCommand::class, ListObservabilityStacksCommand::class)
            ->setArgument('$registry', new Reference(ObservabilityTemplateRegistry::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(PublishObservabilityTemplatesCommand::class, PublishObservabilityTemplatesCommand::class)
            ->setArgument('$publisher', new Reference(ObservabilityTemplatePublisher::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}

