<?php

// require_once __DIR__ . '/../vendor/autoload.php';

// use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
// use Doctrine\Migrations\Configuration\Migration\PhpFile;
// use Doctrine\Migrations\DependencyFactory;
// use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
// use Doctrine\Migrations\Tools\Console\Command\ExecuteCommand;
// use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
// use Doctrine\ORM\Tools\Console\Command\SchemaTool\DropCommand;
// use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
// use Fortizan\Tekton\Database\DoctrineFactory;
// use Symfony\Component\Console\Application;
// use Symfony\Component\Dotenv\Dotenv;

// $dotEnv = new Dotenv();
// $dotEnv->load(__DIR__. "/../.env");

// $configurationFile = new PhpFile('migrations.php');

// $entityFactory = new DoctrineFactory();

// $connectionParams = [
//     'host' => $_ENV['POSTGRES_HOST'],
//     'user' => $_ENV['POSTGRES_USER'],
//     'password' => $_ENV['POSTGRES_PASSWORD'],
//     'dbname' => $_ENV['POSTGRES_DB'],
//     'driver' => 'pdo_pgsql'
// ];

// $entityPaths = [__DIR__ ."/../src/User/Domain/Entity"];

// $entityManager = $entityFactory->createEntityManager($connectionParams, $entityPaths, true);

// $loader = new ExistingEntityManager($entityManager);

// $dependencyFactory = DependencyFactory::fromEntityManager($configurationFile, $loader);

// $entityProvider = new SingleManagerProvider($entityManager);

// $application = new Application();
// $application->addCommand(new DiffCommand($dependencyFactory));
// $application->addCommand(new MigrateCommand($dependencyFactory));
// $application->addCommand(new ExecuteCommand($dependencyFactory));
// $application->addCommand(new DropCommand($entityProvider));

// $application->run();

$paths = glob(__DIR__ . "/*/Domain/Entity/*.php");
var_dump($paths);