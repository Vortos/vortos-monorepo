<?php

declare(strict_types=1);

namespace Vortos\Tests\Cqrs;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cqrs\Command\Idempotency\RedisCommandIdempotencyStore;

final class RedisCommandIdempotencyStoreTest extends TestCase
{
    private InMemoryAdapter $cache;
    private RedisCommandIdempotencyStore $store;

    protected function setUp(): void
    {
        $this->cache = new InMemoryAdapter();
        $this->store = new RedisCommandIdempotencyStore($this->cache);
    }

    public function test_was_not_processed_initially(): void
    {
        $this->assertFalse($this->store->wasProcessed('key-1'));
    }

    public function test_mark_processed_makes_key_visible(): void
    {
        $this->store->markProcessed('key-1');

        $this->assertTrue($this->store->wasProcessed('key-1'));
    }

    public function test_different_keys_are_independent(): void
    {
        $this->store->markProcessed('key-1');

        $this->assertFalse($this->store->wasProcessed('key-2'));
    }

    public function test_try_mark_processed_returns_true_on_first_call(): void
    {
        $this->assertTrue($this->store->tryMarkProcessed('key-1'));
    }

    public function test_try_mark_processed_returns_false_on_second_call(): void
    {
        $this->store->tryMarkProcessed('key-1');

        $this->assertFalse($this->store->tryMarkProcessed('key-1'));
    }

    public function test_try_mark_processed_is_atomic_no_overwrite(): void
    {
        $this->store->markProcessed('key-1');

        $result = $this->store->tryMarkProcessed('key-1');

        $this->assertFalse($result);
        $this->assertTrue($this->store->wasProcessed('key-1'));
    }

    public function test_release_processed_clears_key(): void
    {
        $this->store->markProcessed('key-1');
        $this->store->releaseProcessed('key-1');

        $this->assertFalse($this->store->wasProcessed('key-1'));
    }

    public function test_release_processed_allows_reuse_of_key(): void
    {
        $this->store->tryMarkProcessed('key-1');
        $this->store->releaseProcessed('key-1');

        $this->assertTrue($this->store->tryMarkProcessed('key-1'));
    }

    public function test_key_prefix_is_applied(): void
    {
        $this->store->markProcessed('my-key');

        $this->assertFalse($this->cache->has('my-key'));
        $this->assertTrue($this->cache->has('vortos_cmd_idempotency_my-key'));
    }
}
