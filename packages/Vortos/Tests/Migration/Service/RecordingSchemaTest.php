<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\RecordingSchema;

final class RecordingSchemaTest extends TestCase
{
    public function test_records_created_table_name(): void
    {
        $schema = new RecordingSchema();
        $schema->createTable('orders');

        $ownership = $schema->capturedOwnership();

        $this->assertContains('orders', $ownership->tables());
    }

    public function test_records_multiple_tables(): void
    {
        $schema = new RecordingSchema();
        $schema->createTable('orders');
        $schema->createTable('order_items');

        $ownership = $schema->capturedOwnership();

        $this->assertContains('orders', $ownership->tables());
        $this->assertContains('order_items', $ownership->tables());
    }

    public function test_records_indexes_on_created_tables(): void
    {
        $schema = new RecordingSchema();
        $table = $schema->createTable('orders');
        $table->addColumn('status', 'string');
        $table->addIndex(['status'], 'idx_orders_status');

        $ownership = $schema->capturedOwnership();

        $this->assertContains('idx_orders_status', $ownership->indexes());
    }

    public function test_empty_when_no_tables_created(): void
    {
        $schema = new RecordingSchema();

        $this->assertFalse($schema->hasCapturedObjects());
        $this->assertSame([], $schema->capturedOwnership()->tables());
    }

    public function test_has_captured_objects_true_after_create_table(): void
    {
        $schema = new RecordingSchema();
        $schema->createTable('payments');

        $this->assertTrue($schema->hasCapturedObjects());
    }

    public function test_ownership_reflects_all_captured_tables(): void
    {
        $schema = new RecordingSchema();
        $schema->createTable('users');
        $schema->createTable('sessions');

        $tables = $schema->capturedOwnership()->tables();

        $this->assertCount(2, $tables);
        $this->assertContains('users', $tables);
        $this->assertContains('sessions', $tables);
    }
}
