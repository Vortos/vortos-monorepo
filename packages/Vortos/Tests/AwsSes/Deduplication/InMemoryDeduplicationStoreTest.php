<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Deduplication;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Deduplication\InMemoryDeduplicationStore;
use Vortos\AwsSes\ValueObject\SentEmail;

final class InMemoryDeduplicationStoreTest extends TestCase
{
    private function makeSentEmail(string $id = 'msg-1'): SentEmail
    {
        return new SentEmail($id, new \DateTimeImmutable(), 1, 'log', null);
    }

    public function test_is_not_duplicate_initially(): void
    {
        $store = new InMemoryDeduplicationStore();
        $this->assertFalse($store->isDuplicate('key-1'));
    }

    public function test_is_duplicate_after_mark_sent(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->markSent('key-1', $this->makeSentEmail());

        $this->assertTrue($store->isDuplicate('key-1'));
    }

    public function test_get_sent_returns_null_when_not_stored(): void
    {
        $store = new InMemoryDeduplicationStore();
        $this->assertNull($store->getSent('missing'));
    }

    public function test_get_sent_returns_stored_email(): void
    {
        $store = new InMemoryDeduplicationStore();
        $sent  = $this->makeSentEmail('abc');
        $store->markSent('key-1', $sent);

        $this->assertSame($sent, $store->getSent('key-1'));
    }

    public function test_different_keys_are_independent(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->markSent('key-1', $this->makeSentEmail('id-1'));

        $this->assertTrue($store->isDuplicate('key-1'));
        $this->assertFalse($store->isDuplicate('key-2'));
    }

    public function test_expired_entries_are_pruned(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->markSent('expired', $this->makeSentEmail(), ttlSeconds: -1); // already expired

        $this->assertFalse($store->isDuplicate('expired'));
        $this->assertNull($store->getSent('expired'));
    }

    public function test_non_expired_entries_survive(): void
    {
        $store = new InMemoryDeduplicationStore();
        $sent  = $this->makeSentEmail();
        $store->markSent('live', $sent, ttlSeconds: 3600);

        $this->assertTrue($store->isDuplicate('live'));
        $this->assertSame($sent, $store->getSent('live'));
    }

    public function test_mark_sent_overwrites_existing_key(): void
    {
        $store = new InMemoryDeduplicationStore();
        $first  = $this->makeSentEmail('first');
        $second = $this->makeSentEmail('second');

        $store->markSent('key', $first);
        $store->markSent('key', $second);

        $this->assertSame($second, $store->getSent('key'));
    }
}
