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
}
