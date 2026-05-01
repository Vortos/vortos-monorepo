<?php

declare(strict_types=1);

namespace Vortos\Make\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Make\Command\MakeConsumerCommand;
use Vortos\Make\Command\MakeContextCommand;
use Vortos\Make\Command\MakeCqrsCommandCommand;
use Vortos\Make\Command\MakeControllerCommand;
use Vortos\Make\Command\MakeDomainEventCommand;
use Vortos\Make\Command\MakeDomainExceptionCommand;
use Vortos\Make\Command\MakeEntityCommand;
use Vortos\Make\Command\MakeHookCommand;
use Vortos\Make\Command\MakeMessagingConfigCommand;
use Vortos\Make\Command\MakeMiddlewareCommand;
use Vortos\Make\Command\MakePolicyCommand;
use Vortos\Make\Command\MakeProjectionHandlerCommand;
use Vortos\Make\Command\MakeQueryCommand;
use Vortos\Make\Command\MakeReadRepositoryCommand;
use Vortos\Make\Command\MakeValueObjectCommand;
use Vortos\Make\Command\MakeWriteRepositoryCommand;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\Make\Scanner\StubScanner;

final class MakeExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_make';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');

        if (!$container->has(ModulePathResolver::class)) {
            $container->register(ModulePathResolver::class, ModulePathResolver::class)
                ->setArgument('$projectDir', $projectDir)
                ->setShared(true)
                ->setPublic(false);
        }

        $container->register(StubScanner::class, StubScanner::class)
            ->setArguments([new Reference(ModulePathResolver::class), $projectDir])
            ->setPublic(false);

        $container->register(GeneratorEngine::class, GeneratorEngine::class)
            ->setArguments([new Reference(StubScanner::class), $projectDir])
            ->setPublic(false);

        $commands = [
            MakeContextCommand::class,
            MakeEntityCommand::class,
            MakeValueObjectCommand::class,
            MakeDomainEventCommand::class,
            MakeDomainExceptionCommand::class,
            MakeCqrsCommandCommand::class,
            MakeQueryCommand::class,
            MakeProjectionHandlerCommand::class,
            MakeConsumerCommand::class,
            MakeMessagingConfigCommand::class,
            MakeMiddlewareCommand::class,
            MakeHookCommand::class,
            MakeControllerCommand::class,
            MakeWriteRepositoryCommand::class,
            MakeReadRepositoryCommand::class,
            MakePolicyCommand::class,
        ];

        foreach ($commands as $class) {
            $container->register($class, $class)
                ->setArgument('$engine', new Reference(GeneratorEngine::class))
                ->addTag('console.command')
                ->setPublic(false);
        }
    }
}
