<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Audit\AuditEntry;

final class AuditEntryTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function makeEntry(array $overrides = []): AuditEntry
    {
        return AuditEntry::fromArray(array_merge([
            'entry_id' => 'e1',
            'sequence' => 0,
            'event_type' => 'DeployAttempted',
            'actor_id' => 'alice',
            'actor_identity_source' => 'oidc',
            'env' => 'prod',
            'build_id' => 'build-1',
            'git_sha' => 'abc123',
            'image_digest' => 'sha256:' . str_repeat('a', 64),
            'schema_fingerprint_id' => 'fp-1',
            'reason' => null,
            'occurred_at' => '2026-01-01T00:00:00+00:00',
            'data' => ['n' => 1],
            'prev_hash' => 'prev',
            'content_hash' => 'content',
            'signature' => 'sig',
        ], $overrides));
    }

    public function test_round_trips_through_array(): void
    {
        $entry = $this->makeEntry();
        $restored = AuditEntry::fromArray($entry->toArray());

        self::assertEquals($entry->toArray(), $restored->toArray());
    }

    public function test_negative_sequence_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeEntry(['sequence' => -1]);
    }

    public function test_hashable_fields_excludes_chain_fields(): void
    {
        $entry = $this->makeEntry();
        $fields = $entry->hashableFields();

        self::assertArrayNotHasKey('prev_hash', $fields);
        self::assertArrayNotHasKey('content_hash', $fields);
        self::assertArrayNotHasKey('signature', $fields);
        self::assertArrayHasKey('entry_id', $fields);
    }

    public function test_nullable_reason_preserved(): void
    {
        $entry = $this->makeEntry(['reason' => 'manual hotfix']);

        self::assertSame('manual hotfix', $entry->reason);
    }
}
