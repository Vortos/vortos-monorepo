<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Deduplication;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\AwsSes\Deduplication\RedisDeduplicationStore;
use Vortos\AwsSes\ValueObject\SentEmail;

final class RedisDeduplicationStoreTest extends TestCase
{
    private function makeSentEmail(string $id = 'msg-1'): SentEmail
    {
        return new SentEmail($id, new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
    }

    /** @return AtomicCacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function makeCache(): AtomicCacheInterface
    {
        return $this->createMock(AtomicCacheInterface::class);
    }

    public function test_find_sent_returns_null_when_key_absent(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->with('ses_dedup:abc')->willReturn(null);

        $store = new RedisDeduplicationStore($cache);

        $this->assertNull($store->findSent('abc'));
    }

    public function test_find_sent_returns_stored_email(): void
    {
        $sent  = $this->makeSentEmail('stored');
        $cache = $this->makeCache();
        $cache->method('get')->with('ses_dedup:k1')->willReturn($sent);

        $store = new RedisDeduplicationStore($cache);

        $this->assertSame($sent, $store->findSent('k1'));
    }

    public function test_find_sent_returns_null_when_value_is_wrong_type(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->willReturn('unexpected-string');

        $store = new RedisDeduplicationStore($cache);

        $this->assertNull($store->findSent('k'));
    }

    public function test_mark_sent_calls_set_nx_with_single_key(): void
    {
        $sent  = $this->makeSentEmail('id-42');
        $cache = $this->makeCache();
        $cache->expects($this->once())
            ->method('setNx')
            ->with('ses_dedup:k1', $sent, 3600)
            ->willReturn(true);

        $store = new RedisDeduplicationStore($cache);
        $store->markSent('k1', $sent, 3600);
    }

    public function test_mark_sent_first_writer_wins_via_set_nx(): void
    {
        // setNx returns false → another worker already wrote; verify we still call setNx (not set)
        $cache = $this->makeCache();
        $cache->method('setNx')->willReturn(false);
        $cache->expects($this->never())->method('set');

        $store = new RedisDeduplicationStore($cache);
        $store->markSent('k1', $this->makeSentEmail());
    }

    public function test_keys_are_namespaced_with_prefix(): void
    {
        $cache = $this->makeCache();
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('ses_dedup:'))
            ->willReturn(null);

        $store = new RedisDeduplicationStore($cache);
        $store->findSent('some-key');
    }
}
