<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

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

    public function test_create_has_no_integrity_fields_by_default(): void
    {
        $entry = AuditEntry::create('user-1', 'login');
        $this->assertNull($entry->sequence);
        $this->assertNull($entry->prevHash);
        $this->assertNull($entry->contentHash);
        $this->assertNull($entry->signature);
        $this->assertFalse($entry->isChained());
    }

    public function test_with_integrity_returns_chained_entry(): void
    {
        $entry = AuditEntry::create('user-1', 'login');
        $chained = $entry->withIntegrity(0, 'prev-hash', 'content-hash', 'sig');

        $this->assertSame(0, $chained->sequence);
        $this->assertSame('prev-hash', $chained->prevHash);
        $this->assertSame('content-hash', $chained->contentHash);
        $this->assertSame('sig', $chained->signature);
        $this->assertTrue($chained->isChained());
        $this->assertSame($entry->id, $chained->id);
        $this->assertSame($entry->userId, $chained->userId);
    }

    public function test_chained_entry_includes_integrity_in_to_array(): void
    {
        $entry = AuditEntry::create('user-1', 'login')
            ->withIntegrity(5, 'prev', 'content', 'sig');
        $arr = $entry->toArray();

        $this->assertSame(5, $arr['sequence']);
        $this->assertSame('prev', $arr['prev_hash']);
        $this->assertSame('content', $arr['content_hash']);
        $this->assertSame('sig', $arr['signature']);
    }

    public function test_unchained_entry_omits_integrity_from_to_array(): void
    {
        $entry = AuditEntry::create('user-1', 'login');
        $arr = $entry->toArray();

        $this->assertArrayNotHasKey('sequence', $arr);
        $this->assertArrayNotHasKey('prev_hash', $arr);
        $this->assertArrayNotHasKey('content_hash', $arr);
        $this->assertArrayNotHasKey('signature', $arr);
    }

    public function test_from_array_round_trips(): void
    {
        $entry = AuditEntry::create('user-1', 'login', 'res-1', '10.0.0.1', 'Agent', ['key' => 'val'])
            ->withIntegrity(3, 'prev-h', 'content-h', 'sig-h');

        $restored = AuditEntry::fromArray($entry->toArray());

        $this->assertSame($entry->id, $restored->id);
        $this->assertSame($entry->userId, $restored->userId);
        $this->assertSame($entry->action, $restored->action);
        $this->assertSame($entry->resourceId, $restored->resourceId);
        $this->assertSame($entry->sequence, $restored->sequence);
        $this->assertSame($entry->prevHash, $restored->prevHash);
        $this->assertSame($entry->contentHash, $restored->contentHash);
        $this->assertSame($entry->signature, $restored->signature);
    }

    public function test_from_array_without_integrity(): void
    {
        $entry = AuditEntry::create('user-1', 'login');
        $restored = AuditEntry::fromArray($entry->toArray());

        $this->assertNull($restored->sequence);
        $this->assertFalse($restored->isChained());
    }
}
