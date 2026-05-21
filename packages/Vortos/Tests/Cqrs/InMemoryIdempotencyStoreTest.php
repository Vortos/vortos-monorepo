<?php
declare(strict_types=1);

namespace Vortos\Tests\Cqrs;

use PHPUnit\Framework\TestCase;
use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;

final class InMemoryIdempotencyStoreTest extends TestCase
{
    public function test_not_processed_initially(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $this->assertFalse($store->wasProcessed('key-1'));
    }

    public function test_mark_as_processed(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->markProcessed('key-1');
        $this->assertTrue($store->wasProcessed('key-1'));
    }

    public function test_different_keys_are_independent(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->markProcessed('key-1');
        $this->assertFalse($store->wasProcessed('key-2'));
    }

    public function test_clear_resets_all_keys(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->markProcessed('key-1');
        $store->clear();
        $this->assertFalse($store->wasProcessed('key-1'));
    }

    public function test_store_and_get_result(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->storeResult('key-1', 'my-result');
        $this->assertSame('my-result', $store->getResult('key-1'));
    }

    public function test_get_result_returns_null_when_not_stored(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $this->assertNull($store->getResult('unknown-key'));
    }

    public function test_store_result_accepts_objects(): void
    {
        $store  = new InMemoryCommandIdempotencyStore();
        $object = new \stdClass();
        $object->name = 'Dave';
        $store->storeResult('key-1', $object);
        $this->assertSame($object, $store->getResult('key-1'));
    }

    public function test_clear_resets_results(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->storeResult('key-1', 'result');
        $store->clear();
        $this->assertNull($store->getResult('key-1'));
    }

    public function test_release_removes_result(): void
    {
        $store = new InMemoryCommandIdempotencyStore();
        $store->markProcessed('key-1');
        $store->storeResult('key-1', 'result');
        $store->releaseProcessed('key-1');
        $this->assertFalse($store->wasProcessed('key-1'));
        $this->assertNull($store->getResult('key-1'));
    }
}
