<?php

declare(strict_types=1);

namespace Vortos\Tests\FeatureFlags;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Storage\RedisCachingStorage;

final class RedisCachingStorageTest extends TestCase
{
    public function test_find_all_calls_inner_on_first_call(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->once())->method('findAll')->willReturn([]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set');

        $storage = new RedisCachingStorage($inner, $cache);
        $storage->findAll();
    }

    public function test_find_all_returns_cached_on_second_call(): void
    {
        $flags = [$this->flag('flag-a')];

        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->once())->method('findAll')->willReturn($flags);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnOnConsecutiveCalls(null, $flags);
        $cache->method('set');

        $storage = new RedisCachingStorage($inner, $cache);
        $storage->findAll();
        $storage->findAll();
    }

    public function test_save_invalidates_cache(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('save');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete');

        $storage = new RedisCachingStorage($inner, $cache);
        $storage->save($this->flag('flag-a'));
    }

    public function test_delete_invalidates_cache(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('delete');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete');

        $storage = new RedisCachingStorage($inner, $cache);
        $storage->delete('flag-a');
    }

    public function test_find_by_name_returns_matching_flag(): void
    {
        $flagA = $this->flag('flag-a');
        $flagB = $this->flag('flag-b');

        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willReturn([$flagA, $flagB]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set');

        $storage = new RedisCachingStorage($inner, $cache);
        $this->assertSame($flagA, $storage->findByName('flag-a'));
        $this->assertSame($flagB, $storage->findByName('flag-b'));
        $this->assertNull($storage->findByName('flag-x'));
    }

    public function test_works_without_cache(): void
    {
        $flags = [$this->flag('flag-a')];

        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willReturn($flags);

        $storage = new RedisCachingStorage($inner, null);
        $this->assertSame($flags, $storage->findAll());
    }

    public function test_save_and_delete_work_without_cache(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->once())->method('save');
        $inner->expects($this->once())->method('delete');

        $storage = new RedisCachingStorage($inner, null);
        $storage->save($this->flag('flag-a'));
        $storage->delete('flag-a');
    }

    private function flag(string $name): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id-1', $name, '', true, [], null, $now, $now);
    }
}
