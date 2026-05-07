<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceDbal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceDbal\Connection\ConnectionFactory;
use Vortos\PersistenceDbal\DependencyInjection\DbalPersistenceExtension;

final class DbalPersistenceExtensionTest extends TestCase
{
    public function test_allows_empty_dsn_so_setup_can_bootstrap_env_file(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.persistence.write_dsn', '');

        (new DbalPersistenceExtension())->load([], $container);

        $this->assertSame('', $container->getDefinition(Connection::class)->getArgument(0));
    }

    public function test_connection_factory_requires_vortos_write_db_dsn_at_runtime(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VORTOS_WRITE_DB_DSN');

        ConnectionFactory::fromDsn('');
    }

    public function test_uses_vortos_write_db_dsn_parameter_for_connection_factory(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.persistence.write_dsn', 'pgsql://postgres:secret@write_db:5432/demo');

        (new DbalPersistenceExtension())->load([], $container);

        $this->assertSame(
            'pgsql://postgres:secret@write_db:5432/demo',
            $container->getDefinition(Connection::class)->getArgument(0),
        );
    }
}
