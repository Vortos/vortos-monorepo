<?php

declare(strict_types=1);

namespace Vortos\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Persistence\DependencyInjection\PersistenceExtension;

final class PersistenceExtensionEnvDefaultsTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach ($this->keys() as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }
    }

    public function test_extension_sets_parameters_from_env_without_project_persistence_config(): void
    {
        $_ENV['VORTOS_WRITE_DB_DSN'] = 'pgsql://postgres:pg-secret@write_db:5432/demo';
        $_ENV['VORTOS_READ_DB_DSN'] = 'mongodb://root:mongo-secret@read_db:27017';
        $_ENV['VORTOS_READ_DB_NAME'] = 'demo';

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_persistence_config');
        $container->setParameter('kernel.env', 'dev');

        (new PersistenceExtension())->load([], $container);

        $this->assertSame('pgsql://postgres:pg-secret@write_db:5432/demo', $container->getParameter('vortos.persistence.write_dsn'));
        $this->assertSame('mongodb://root:mongo-secret@read_db:27017', $container->getParameter('vortos.persistence.read_dsn'));
        $this->assertSame('demo', $container->getParameter('vortos.persistence.read_database'));
    }

    /** @return string[] */
    private function keys(): array
    {
        return [
            'DATABASE_URL',
            'VORTOS_WRITE_DB_DSN',
            'VORTOS_READ_DB_DSN',
            'VORTOS_READ_DB_NAME',
            'MONGO_INITDB_ROOT_USERNAME',
            'MONGO_INITDB_ROOT_PASSWORD',
            'MONGO_HOST',
            'MONGO_PORT',
            'MONGO_DB_NAME',
        ];
    }
}
