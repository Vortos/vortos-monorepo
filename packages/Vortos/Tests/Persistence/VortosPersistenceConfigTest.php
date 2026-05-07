<?php

declare(strict_types=1);

namespace Vortos\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

final class VortosPersistenceConfigTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach ($this->keys() as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
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

    public function test_uses_vortos_write_db_dsn_when_available(): void
    {
        $_ENV['VORTOS_WRITE_DB_DSN'] = 'pgsql://postgres:secret@write_db:5432/demo';

        $config = (new VortosPersistenceConfig())->toArray();

        $this->assertSame('pgsql://postgres:secret@write_db:5432/demo', $config['write']['dsn']);
    }

    public function test_uses_vortos_read_db_env(): void
    {
        $_ENV['VORTOS_READ_DB_DSN'] = 'mongodb://root:mongo-secret@read_db:27018';
        $_ENV['VORTOS_READ_DB_NAME'] = 'demo_read';

        $config = (new VortosPersistenceConfig())->toArray();

        $this->assertSame('mongodb://root:mongo-secret@read_db:27018', $config['read']['dsn']);
        $this->assertSame('demo_read', $config['read']['database']);
    }

    public function test_does_not_read_vendor_specific_env_as_app_config(): void
    {
        $_ENV['DATABASE_URL'] = 'pgsql://postgres:legacy@write_db:5432/legacy';
        $_ENV['POSTGRES_PASSWORD'] = 'pg-secret';
        $_ENV['MONGO_DB_NAME'] = 'legacy_read';

        $config = (new VortosPersistenceConfig())->toArray();

        $this->assertSame('', $config['write']['dsn']);
        $this->assertSame('', $config['read']['dsn']);
        $this->assertSame('', $config['read']['database']);
    }

    /** @return string[] */
    private function keys(): array
    {
        return [
            'VORTOS_WRITE_DB_DSN',
            'VORTOS_READ_DB_DSN',
            'VORTOS_READ_DB_NAME',
            'DATABASE_URL',
            'POSTGRES_USER',
            'POSTGRES_PASSWORD',
            'POSTGRES_HOST',
            'POSTGRES_DB',
            'MONGO_INITDB_ROOT_USERNAME',
            'MONGO_INITDB_ROOT_PASSWORD',
            'MONGO_HOST',
            'MONGO_PORT',
            'MONGO_DB_NAME',
            'APP_NAME',
        ];
    }
}
