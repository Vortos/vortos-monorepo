<?php
declare(strict_types=1);

namespace Vortos\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Collection\Collection;

final class StringItem
{
    public function __construct(public readonly string $value) {}
}

final class StringCollection extends Collection
{
    protected function itemType(): string { return StringItem::class; }
}

final class CollectionTest extends TestCase
{
    public function test_empty_collection(): void
    {
        $collection = new StringCollection();
        $this->assertCount(0, $collection);
        $this->assertTrue($collection->isEmpty());
    }

    public function test_collection_with_items(): void
    {
        $collection = new StringCollection();
        $collection->add(new StringItem('a'));
        $collection->add(new StringItem('b'));
        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());
    }

    public function test_filter(): void
    {
        $collection = new StringCollection();
        $collection->add(new StringItem('apple'));
        $collection->add(new StringItem('banana'));
        $collection->add(new StringItem('apricot'));

        $filtered = $collection->filter(fn($i) => str_starts_with($i->value, 'a'));
        $this->assertCount(2, $filtered);
    }

    public function test_first(): void
    {
        $collection = new StringCollection();
        $collection->add(new StringItem('first'));
        $collection->add(new StringItem('second'));

        $result = $collection->first(fn($i) => $i->value === 'first');
        $this->assertSame('first', $result->value);
    }

    public function test_first_returns_null_when_not_found(): void
    {
        $collection = new StringCollection();
        $this->assertNull($collection->first(fn($i) => false));
    }

    public function test_to_array(): void
    {
        $collection = new StringCollection();
        $item = new StringItem('test');
        $collection->add($item);
        $this->assertSame([$item], $collection->toArray());
    }

    public function test_contains(): void
    {
        $collection = new StringCollection();
        $item = new StringItem('test');
        $this->assertFalse($collection->contains($item));
        $collection->add($item);
        $this->assertTrue($collection->contains($item));
    }

    public function test_remove(): void
    {
        $collection = new StringCollection();
        $item = new StringItem('test');
        $collection->add($item);
        $collection->remove($item);
        $this->assertCount(0, $collection);
    }

    public function test_throws_on_wrong_type(): void
    {
        $collection = new StringCollection();
        $this->expectException(\InvalidArgumentException::class);
        $collection->add(new \stdClass());
    }
}
