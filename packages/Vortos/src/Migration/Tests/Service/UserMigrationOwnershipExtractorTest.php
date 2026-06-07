<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vortos\Migration\Service\UserMigrationOwnershipExtractor;

final class UserMigrationOwnershipExtractorTest extends TestCase
{
    public function test_extracts_ownership_from_schema_api_migration(): void
    {
        $migration = new class($this->createMock(Connection::class), $this->createMock(LoggerInterface::class)) extends AbstractMigration {
            public function up(Schema $schema): void
            {
                $schema->createTable('orders');
            }

            public function down(Schema $schema): void {}
        };

        $extractor = new UserMigrationOwnershipExtractor($this->createMock(Connection::class));
        $ownership = $extractor->extract($migration::class);

        $this->assertNotNull($ownership);
        $this->assertContains('orders', $ownership->tables());
    }

    public function test_returns_null_for_raw_sql_migration(): void
    {
        $migration = new class($this->createMock(Connection::class), $this->createMock(LoggerInterface::class)) extends AbstractMigration {
            public function up(Schema $schema): void
            {
                $this->addSql('CREATE TABLE IF NOT EXISTS orders (id UUID PRIMARY KEY)');
            }

            public function down(Schema $schema): void {}
        };

        $extractor = new UserMigrationOwnershipExtractor($this->createMock(Connection::class));
        $ownership = $extractor->extract($migration::class);

        $this->assertNull($ownership);
    }

    public function test_returns_null_when_up_throws(): void
    {
        $migration = new class($this->createMock(Connection::class), $this->createMock(LoggerInterface::class)) extends AbstractMigration {
            public function up(Schema $schema): void
            {
                throw new \RuntimeException('Unexpected error');
            }

            public function down(Schema $schema): void {}
        };

        $extractor = new UserMigrationOwnershipExtractor($this->createMock(Connection::class));
        $ownership = $extractor->extract($migration::class);

        $this->assertNull($ownership);
    }

    public function test_returns_null_for_non_migration_class(): void
    {
        $extractor = new UserMigrationOwnershipExtractor($this->createMock(Connection::class));
        $ownership = $extractor->extract(\stdClass::class);

        $this->assertNull($ownership);
    }

    public function test_extracts_multiple_tables(): void
    {
        $migration = new class($this->createMock(Connection::class), $this->createMock(LoggerInterface::class)) extends AbstractMigration {
            public function up(Schema $schema): void
            {
                $schema->createTable('orders');
                $schema->createTable('order_items');
            }

            public function down(Schema $schema): void {}
        };

        $extractor = new UserMigrationOwnershipExtractor($this->createMock(Connection::class));
        $ownership = $extractor->extract($migration::class);

        $this->assertNotNull($ownership);
        $this->assertContains('orders', $ownership->tables());
        $this->assertContains('order_items', $ownership->tables());
    }
}
