<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceMongo;

use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceMongo\Connection\MongoClientFactory;
use Vortos\PersistenceMongo\DependencyInjection\MongoPersistenceExtension;

final class MongoPersistenceExtensionTest extends TestCase
{
    public function test_allows_empty_read_db_values_so_setup_can_bootstrap_env_file(): void
    {
        $container = $this->container('', '');

        (new MongoPersistenceExtension())->load([], $container);

        $this->assertSame('', $container->getDefinition(Client::class)->getArgument(0));
        $this->assertSame('', $container->getParameter('vortos.persistence.mongo.database_name'));
    }

    public function test_client_factory_requires_vortos_read_db_dsn_at_runtime(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VORTOS_READ_DB_DSN');

        MongoClientFactory::fromDsn('');
    }

    public function test_uses_vortos_read_db_parameters_for_client_factory(): void
    {
        $container = $this->container('mongodb://root:secret@read_db:27017', 'demo');

        (new MongoPersistenceExtension())->load([], $container);

        $this->assertSame(
            'mongodb://root:secret@read_db:27017',
            $container->getDefinition(Client::class)->getArgument(0),
        );
        $this->assertSame('demo', $container->getParameter('vortos.persistence.mongo.database_name'));
    }

    private function container(string $dsn, string $database): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.persistence.read_dsn', $dsn);
        $container->setParameter('vortos.persistence.read_database', $database);

        return $container;
    }
}
