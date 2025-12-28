<?php

use Fortizan\Tekton\Persistence\Contract\PersistenceFactoryInterface;
use Fortizan\Tekton\Persistence\Contract\PersistenceManagerInterface;
use Fortizan\Tekton\Persistence\Contract\ProjectionReaderInterface;
use Fortizan\Tekton\Persistence\Contract\ProjectionWriterInterface;
use Fortizan\Tekton\Persistence\Contract\SourceReaderInterface;
use Fortizan\Tekton\Persistence\Contract\SourceWriterInterface;
use Fortizan\Tekton\Persistence\PersistenceFactory;
use Fortizan\Tekton\Persistence\PersistenceManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator) {

    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(PersistenceFactory::class);
        // ->args(['%kernel.project_dir%']);

    $services->alias(PersistenceFactoryInterface::class, PersistenceFactory::class);
    $services->alias(PersistenceManagerInterface::class, PersistenceManager::class);

    $services->set(SourceReaderInterface::class)
        ->factory([service(PersistenceFactoryInterface::class), 'createSourceReader'])
        ->args([[], "%kernel.debug%"]);

    $services->set(SourceWriterInterface::class)
        ->factory([service(PersistenceFactoryInterface::class), 'createSourceWriter'])
        ->args([new Reference(MessageBusInterface::class), [], [], "%kernel.debug%"]);

    $services->set(ProjectionWriterInterface::class)
        ->factory([service(PersistenceFactoryInterface::class), 'createProjectionWriter'])
        ->args([[]]);

    $services->set(ProjectionReaderInterface::class)
        ->factory([service(PersistenceFactoryInterface::class), 'createProjectionReader'])
        ->args([[]]);
};
