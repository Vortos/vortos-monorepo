<?php

use Vortos\DependencyInjection\Compiler\Bus\EventAttributeCompilerPass;
use Vortos\DependencyInjection\Compiler\Bus\EventRegistryCompilerPass;
use Vortos\DependencyInjection\Compiler\Cqrs\CommandHandlerPass;
use Vortos\DependencyInjection\Compiler\Cqrs\QueryHandlerPass;
use Vortos\DependencyInjection\Compiler\Http\HttpListenerCompilerPass;
use Vortos\DependencyInjection\Compiler\Http\RegisterEventSubscribersPass;
use Vortos\DependencyInjection\Compiler\Messenger\Consumer\ConsumerHandlersMapCompilerPass;
use Vortos\DependencyInjection\Compiler\Messenger\Consumer\ConsumerTransportPass;
use Vortos\DependencyInjection\Compiler\Messenger\Producer\ProducerTopicMapCompilerPass;
use Vortos\DependencyInjection\Compiler\Projection\ProjectionHandlerPass;
use Vortos\DependencyInjection\Compiler\Route\RouteCompilerPass;
use Vortos\DependencyInjection\Compiler\Serialize\SerializerCompilerPass;
use Vortos\DependencyInjection\VortosExtension;
use Vortos\Messaging\DependencyInjection\MessagingPackage;
use Vortos\Tracing\DependencyInjection\TracingPackage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;
use Vortos\Cache\DependencyInjection\CachePackage;
use Vortos\Cqrs\DependencyInjection\CqrsPackage;
use Vortos\Persistence\DependencyInjection\PersistencePackage;
use Vortos\PersistenceDbal\DependencyInjection\DbalPersistencePackage;
use Vortos\PersistenceMongo\DependencyInjection\MongoPersistencePackage;

$container = new ContainerBuilder();

//  setting up global parameters
$container->setParameter('kernel.project_dir', __DIR__ . '/../../../..');
$container->setParameter('charset', 'UTF-8');
$container->setParameter('kernel.log_path', __DIR__ . '/../../../../var/log');
$container->setParameter('MESSENGER_TRANSPORT_DSN', $_ENV['MESSENGER_TRANSPORT_DSN']);

// loading framework specific services and configurations
$extension = new VortosExtension();
$container->registerExtension($extension);
$container->loadFromExtension($extension->getAlias());

// loading application specific services and configurations
$loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../../config'));
$loader->load('services.php');

$container->register(Application::class, Application::class)
    ->setArguments(['Vortos Custom Framework', '1.0.0'])
    ->setPublic(true);

$packages = [
    new CachePackage(),
    new MessagingPackage(),
    new TracingPackage(),
    new PersistencePackage(),       
    new DbalPersistencePackage(),   
    new MongoPersistencePackage(),
    new CqrsPackage(),
];

foreach ($packages as $package) {
    $package->build($container);

    $extension = $package->getContainerExtension();
    $container->registerExtension($extension);
    $container->loadFromExtension($extension->getAlias());
}

// $container->addCompilerPass(new EventRegistryCompilerPass());
$container->addCompilerPass(new QueryHandlerPass());
$container->addCompilerPass(new CommandHandlerPass());
$container->addCompilerPass(new MessengerPass());
$container->addCompilerPass(new ProjectionHandlerPass());
$container->addCompilerPass(new EventAttributeCompilerPass());
$container->addCompilerPass(new HttpListenerCompilerPass());
$container->addCompilerPass(new RegisterEventSubscribersPass());
$container->addCompilerPass(new RouteCompilerPass());
$container->addCompilerPass(new SerializerCompilerPass());
$container->addCompilerPass(new ConsumerHandlersMapCompilerPass());
$container->addCompilerPass(new ConsumerTransportPass());
$container->addCompilerPass(new ProducerTopicMapCompilerPass());


return $container;
