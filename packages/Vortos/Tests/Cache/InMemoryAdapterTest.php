<?php
declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\InMemoryAdapter;

final class InMemoryAdapterTest extends TestCase
{
    private InMemoryAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryAdapter();
    }

    public function test_set_and_get(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertSame('default', $this->cache->get('missing', 'default'));
    }

    public function test_has_returns_true_when_exists(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
    }

    public function test_has_returns_false_when_missing(): void
    {
        $this->assertFalse($this->cache->has('missing'));
    }

    public function test_delete(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->delete('key');
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_clear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->clear();
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function test_set_multiple_and_get_multiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $result = iterator_to_array($this->cache->getMultiple(['a', 'b', 'c']));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function test_delete_multiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        $this->cache->deleteMultiple(['a', 'b']);
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function test_set_with_ttl_expires(): void
    {
        $this->cache->set('key', 'value', 1);
        sleep(2);
        $this->assertNull($this->cache->get('key'));
    }

    public function test_tag_invalidation(): void
    {
        $this->cache->setWithTags('key1', 'value1', ['tag-a']);
        $this->cache->setWithTags('key2', 'value2', ['tag-a', 'tag-b']);
        $this->cache->setWithTags('key3', 'value3', ['tag-b']);

        $this->cache->invalidateTags(['tag-a']);

        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertSame('value3', $this->cache->get('key3'));
    }

    public function test_set_with_tags_and_ttl(): void
    {
        $this->cache->setWithTags('key', 'value', ['tag-a'], 3600);
        $this->assertSame('value', $this->cache->get('key'));
    }
}
