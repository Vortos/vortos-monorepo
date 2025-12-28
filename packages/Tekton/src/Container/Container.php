<?php

use Fortizan\Tekton\DependencyInjection\Compiler\Cqrs\CommandHandlerPass;
use Fortizan\Tekton\DependencyInjection\Compiler\Cqrs\QueryHandlerPass;
use Fortizan\Tekton\DependencyInjection\Compiler\Http\RegisterEventSubscribersPass;
use Fortizan\Tekton\DependencyInjection\TektonExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;


$container = new ContainerBuilder();

//  setting up global parameters
$container->setParameter('kernel.project_dir', __DIR__ . '/../../../..');
$container->setParameter('charset', 'UTF-8');
$container->setParameter('kernel.log_path', __DIR__ . '/../../../../var/log');

// loading framework specific services and configurations
$extension = new TektonExtension();
$container->registerExtension($extension);
$container->loadFromExtension($extension->getAlias());

// loading application specific services and configurations
$loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../../config'));
$loader->load('services.php');

$container->addCompilerPass(new QueryHandlerPass());
$container->addCompilerPass(new CommandHandlerPass());
$container->addCompilerPass(new MessengerPass());
$container->addCompilerPass(new RegisterEventSubscribersPass());

return $container;
