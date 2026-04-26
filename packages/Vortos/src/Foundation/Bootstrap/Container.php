<?php

use Vortos\Auth\DependencyInjection\AuthPackage;
use Vortos\Authorization\DependencyInjection\AuthorizationPackage;
use Vortos\Cache\DependencyInjection\CachePackage;
use Vortos\Cqrs\DependencyInjection\CqrsPackage;
use Vortos\Http\DependencyInjection\HttpPackage;
use Vortos\Logger\DependencyInjection\LoggerPackage;
use Vortos\Messaging\DependencyInjection\MessagingPackage;
use Vortos\Persistence\DependencyInjection\PersistencePackage;
use Vortos\PersistenceDbal\DependencyInjection\DbalPersistencePackage;
use Vortos\PersistenceMongo\DependencyInjection\MongoPersistencePackage;
use Vortos\Tracing\DependencyInjection\TracingPackage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

// $projectRoot is injected by Runner before include
$container = new ContainerBuilder();

$container->setParameter('kernel.project_dir', $projectRoot);
$container->setParameter('charset', 'UTF-8');
$container->setParameter('kernel.log_path', $projectRoot . '/var/log');

$container->register(Application::class, Application::class)
    ->setArguments(['Vortos', '1.0.0-alpha'])
    ->setPublic(true);

$packages = [
    new LoggerPackage(),
    new HttpPackage(),
    new CachePackage(),
    new MessagingPackage(),
    new TracingPackage(),
    new PersistencePackage(),
    new DbalPersistencePackage(),
    new MongoPersistencePackage(),
    new CqrsPackage(),
    new AuthPackage(),
    new AuthorizationPackage(),
];

foreach ($packages as $package) {
    $package->build($container);
    $extension = $package->getContainerExtension();
    $container->registerExtension($extension);
    $container->loadFromExtension($extension->getAlias());
}

// Load application services — from project root config/
$loader = new PhpFileLoader($container, new FileLocator($projectRoot . '/config'));
$loader->load('services.php');

// Load framework services — from package's own config/ if it exists
$frameworkConfig = __DIR__ . '/../../../config/services.php';
if (file_exists($frameworkConfig)) {
    $loader2 = new PhpFileLoader($container, new FileLocator(dirname($frameworkConfig)));
    $loader2->load('services.php');
}

return $container;
