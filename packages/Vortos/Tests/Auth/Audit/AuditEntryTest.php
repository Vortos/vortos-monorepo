<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\AuditEntry;

final class AuditEntryTest extends TestCase
{
    public function test_create_generates_unique_id(): void
    {
        $a = AuditEntry::create('user-1', 'document.viewed');
        $b = AuditEntry::create('user-1', 'document.viewed');
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_create_sets_occurred_at(): void
    {
        $before = new \DateTimeImmutable();
        $entry = AuditEntry::create('user-1', 'document.viewed');
        $after = new \DateTimeImmutable();
        $this->assertGreaterThanOrEqual($before, $entry->occurredAt);
        $this->assertLessThanOrEqual($after, $entry->occurredAt);
    }

    public function test_create_stores_user_id_and_action(): void
    {
        $entry = AuditEntry::create('user-123', 'document.deleted', 'doc-456');
        $this->assertSame('user-123', $entry->userId);
        $this->assertSame('document.deleted', $entry->action);
        $this->assertSame('doc-456', $entry->resourceId);
    }

    public function test_create_stores_ip_and_user_agent(): void
    {
        $entry = AuditEntry::create('user-1', 'login', null, '192.168.1.1', 'Mozilla/5.0');
        $this->assertSame('192.168.1.1', $entry->ipAddress);
        $this->assertSame('Mozilla/5.0', $entry->userAgent);
    }

    public function test_create_stores_metadata(): void
    {
        $entry = AuditEntry::create('user-1', 'export', null, '', '', ['format' => 'csv', 'count' => 100]);
        $this->assertSame('csv', $entry->metadata['format']);
        $this->assertSame(100, $entry->metadata['count']);
    }

    public function test_to_array_has_correct_shape(): void
    {
        $entry = AuditEntry::create('user-1', 'document.viewed', 'doc-1', '10.0.0.1', 'Agent');
        $arr = $entry->toArray();
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('user_id', $arr);
        $this->assertArrayHasKey('action', $arr);
        $this->assertArrayHasKey('resource', $arr);
        $this->assertArrayHasKey('ip', $arr);
        $this->assertArrayHasKey('user_agent', $arr);
        $this->assertArrayHasKey('metadata', $arr);
        $this->assertArrayHasKey('created_at', $arr);
    }

    public function test_null_resource_id_is_null_in_array(): void
    {
        $entry = AuditEntry::create('user-1', 'login');
        $this->assertNull($entry->toArray()['resource']);
    }
}
