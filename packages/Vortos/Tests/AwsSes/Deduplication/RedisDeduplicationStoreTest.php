<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Deduplication;

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

    public function test_is_duplicate_returns_false_when_flag_absent(): void
    {
        $cache = $this->makeCache();
        $cache->method('has')->with('ses_dedup:abc:flag')->willReturn(false);

        $store = new RedisDeduplicationStore($cache);

        $this->assertFalse($store->isDuplicate('abc'));
    }

    public function test_is_duplicate_returns_true_when_flag_present(): void
    {
        $cache = $this->makeCache();
        $cache->method('has')->with('ses_dedup:abc:flag')->willReturn(true);

        $store = new RedisDeduplicationStore($cache);

        $this->assertTrue($store->isDuplicate('abc'));
    }

    public function test_mark_sent_calls_set_nx_on_flag_key(): void
    {
        $cache = $this->makeCache();
        $cache->expects($this->once())
            ->method('setNx')
            ->with('ses_dedup:k1:flag', true, 3600)
            ->willReturn(true);
        $cache->method('set');

        $store = new RedisDeduplicationStore($cache);
        $store->markSent('k1', $this->makeSentEmail(), 3600);
    }

    public function test_mark_sent_stores_result_when_set_nx_succeeds(): void
    {
        $sent  = $this->makeSentEmail('id-42');
        $cache = $this->makeCache();
        $cache->method('setNx')->willReturn(true);
        $cache->expects($this->once())
            ->method('set')
            ->with('ses_dedup:k1:result', $sent, 3600);

        $store = new RedisDeduplicationStore($cache);
        $store->markSent('k1', $sent, 3600);
    }

    public function test_mark_sent_skips_result_write_when_set_nx_fails(): void
    {
        // setNx returns false → another worker already wrote; result must not be overwritten
        $cache = $this->makeCache();
        $cache->method('setNx')->willReturn(false);
        $cache->expects($this->never())->method('set');

        $store = new RedisDeduplicationStore($cache);
        $store->markSent('k1', $this->makeSentEmail());
    }

    public function test_get_sent_returns_stored_sent_email(): void
    {
        $sent  = $this->makeSentEmail('stored');
        $cache = $this->makeCache();
        $cache->method('get')->with('ses_dedup:k1:result')->willReturn($sent);

        $store = new RedisDeduplicationStore($cache);

        $this->assertSame($sent, $store->getSent('k1'));
    }

    public function test_get_sent_returns_null_when_key_missing(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->willReturn(null);

        $store = new RedisDeduplicationStore($cache);

        $this->assertNull($store->getSent('missing'));
    }

    public function test_get_sent_returns_null_when_value_is_wrong_type(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->willReturn('unexpected-string');

        $store = new RedisDeduplicationStore($cache);

        $this->assertNull($store->getSent('k'));
    }

    public function test_keys_are_namespaced_with_prefix(): void
    {
        $cache = $this->makeCache();
        $cache->expects($this->once())
            ->method('has')
            ->with($this->stringStartsWith('ses_dedup:'))
            ->willReturn(false);

        $store = new RedisDeduplicationStore($cache);
        $store->isDuplicate('some-key');
    }
}
