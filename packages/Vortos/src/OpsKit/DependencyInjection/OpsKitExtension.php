<?php

declare(strict_types=1);

namespace Vortos\OpsKit\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\OpsKit\Console\DriverScaffolder;
use Vortos\OpsKit\Console\MakeDriverCommand;

final class OpsKitExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_ops_kit';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        $container->register(DriverScaffolder::class, DriverScaffolder::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(MakeDriverCommand::class, MakeDriverCommand::class)
            ->setArgument('$scaffolder', new Reference(DriverScaffolder::class))
            ->addTag('console.command')
            ->setPublic(false);
    }
}
