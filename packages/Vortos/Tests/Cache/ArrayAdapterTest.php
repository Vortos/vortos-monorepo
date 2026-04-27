<?php
declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\ArrayAdapter;

final class ArrayAdapterTest extends TestCase
{
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function test_set_and_get(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', $this->cache->get('nope', 'fallback'));
    }

    public function test_has(): void
    {
        $this->assertFalse($this->cache->has('key'));
        $this->cache->set('key', 'val');
        $this->assertTrue($this->cache->has('key'));
    }

    public function test_delete(): void
    {
        $this->cache->set('key', 'val');
        $this->cache->delete('key');
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_clear(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->clear();
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function test_set_multiple_and_get_multiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        $result = iterator_to_array($this->cache->getMultiple(['a', 'b']));
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function test_delete_multiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        $this->cache->deleteMultiple(['a', 'b']);
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function test_tag_invalidation(): void
    {
        $this->cache->setWithTags('k1', 'v1', ['tag-x']);
        $this->cache->setWithTags('k2', 'v2', ['tag-y']);
        $this->cache->invalidateTags(['tag-x']);
        $this->assertNull($this->cache->get('k1'));
        $this->assertSame('v2', $this->cache->get('k2'));
    }

    public function test_ttl_is_ignored(): void
    {
        $this->cache->set('key', 'value', 1);
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_set_with_multiple_tags(): void
    {
        $this->cache->setWithTags('k1', 'v1', ['tag-a', 'tag-b']);
        $this->cache->invalidateTags(['tag-a']);
        $this->assertNull($this->cache->get('k1'));
    }
}
