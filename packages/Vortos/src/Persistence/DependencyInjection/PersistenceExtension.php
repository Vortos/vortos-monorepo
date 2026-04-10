<?php

declare(strict_types=1);

namespace Vortos\Persistence\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\Persistence\Command\SetupPersistenceCommand;

/**
 * Core persistence extension.
 *
 * Loads user config files and stores resolved DSN values as container parameters.
 * Does NOT register any DB-specific services — that is the responsibility of
 * DbalPersistenceExtension and MongoPersistenceExtension.
 *
 * This separation means a user who only wants MongoDB read repositories
 * can skip DbalPersistenceExtension entirely — no Doctrine DBAL installed,
 * no Connection registered, no database URL required.
 *
 * ## Config loading
 *
 * Loads in order:
 *   1. config/persistence.php          — base config (all environments)
 *   2. config/{env}/persistence.php    — environment override (optional)
 *
 * Each file receives a VortosPersistenceConfig instance and configures it
 * via fluent methods. The env file can override any values from the base file.
 */
final class PersistenceExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_persistence';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $base = $projectDir . '/config/persistence.php';
     
        $config = new VortosPersistenceConfig();

        $base = $projectDir . '/config/persistence.php';
        if (file_exists($base)) {
            (require $base)($config);
        }
        var_dump(json_encode($config->toArray()));

        $envFile = $projectDir . '/config/' . $env . '/persistence.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $container->setParameter('vortos.persistence.write_dsn', $resolved['write']['dsn']);
        $container->setParameter('vortos.persistence.read_dsn', $resolved['read']['dsn']);
        $container->setParameter('vortos.persistence.read_database', $resolved['read']['database']);

        $container->register(SetupPersistenceCommand::class, SetupPersistenceCommand::class)
            ->setArgument('$readRepositories', new TaggedIteratorArgument('vortos.read_repository'))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
