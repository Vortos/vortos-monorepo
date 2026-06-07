<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Deduplication;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Deduplication\InMemoryDeduplicationStore;
use Vortos\AwsSes\ValueObject\SentEmail;

final class InMemoryDeduplicationStoreTest extends TestCase
{
    private function makeSentEmail(string $id = 'msg-1'): SentEmail
    {
        return new SentEmail($id, new \DateTimeImmutable(), 1, 'log', null);
    }

    public function test_find_sent_returns_null_when_not_stored(): void
    {
        $store = new InMemoryDeduplicationStore();
        $this->assertNull($store->findSent('key-1'));
    }

    public function test_find_sent_returns_stored_email_after_mark_sent(): void
    {
        $store = new InMemoryDeduplicationStore();
        $sent  = $this->makeSentEmail('abc');
        $store->markSent('key-1', $sent);

        $this->assertSame($sent, $store->findSent('key-1'));
    }

    public function test_different_keys_are_independent(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->markSent('key-1', $this->makeSentEmail('id-1'));

        $this->assertNotNull($store->findSent('key-1'));
        $this->assertNull($store->findSent('key-2'));
    }

    public function test_expired_entries_are_pruned(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->markSent('expired', $this->makeSentEmail(), ttlSeconds: -1); // already expired

        $this->assertNull($store->findSent('expired'));
    }

    public function test_non_expired_entries_survive(): void
    {
        $store = new InMemoryDeduplicationStore();
        $sent  = $this->makeSentEmail();
        $store->markSent('live', $sent, ttlSeconds: 3600);

        $this->assertSame($sent, $store->findSent('live'));
    }

    public function test_mark_sent_first_writer_wins(): void
    {
        $store  = new InMemoryDeduplicationStore();
        $first  = $this->makeSentEmail('first');
        $second = $this->makeSentEmail('second');

        $store->markSent('key', $first);
        $store->markSent('key', $second); // must not overwrite

        $this->assertSame('first', $store->findSent('key')?->messageId());
    }

    public function test_prune_runs_on_mark_sent_to_bound_memory(): void
    {
        $store = new InMemoryDeduplicationStore();

        // Insert an already-expired entry, then mark another key
        $store->markSent('expired', $this->makeSentEmail(), ttlSeconds: -1);
        $store->markSent('fresh', $this->makeSentEmail('f'), ttlSeconds: 3600);

        // Expired key is gone; fresh key survives
        $this->assertNull($store->findSent('expired'));
        $this->assertNotNull($store->findSent('fresh'));
    }
}
