<?php

declare(strict_types=1);

namespace Vortos\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Cache\Tracing\TracingCacheAdapter;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;

final class TracingCacheAdapterTest extends TestCase
{
    private InMemoryAdapter $inner;

    protected function setUp(): void
    {
        $this->inner = new InMemoryAdapter();
    }

    public function test_set_nx_returns_true_when_key_is_new(): void
    {
        $adapter = new TracingCacheAdapter($this->inner, $this->noOpTracer());

        $this->assertTrue($adapter->setNx('key', 'value', 60));
    }

    public function test_set_nx_returns_false_when_key_already_exists(): void
    {
        $this->inner->set('key', 'existing', 60);
        $adapter = new TracingCacheAdapter($this->inner, $this->noOpTracer());

        $this->assertFalse($adapter->setNx('key', 'new', 60));
    }

    public function test_set_nx_delegates_write_to_inner(): void
    {
        $adapter = new TracingCacheAdapter($this->inner, $this->noOpTracer());
        $adapter->setNx('key', 'value', 60);

        $this->assertSame('value', $this->inner->get('key'));
    }

    public function test_set_nx_does_not_overwrite_existing_value(): void
    {
        $this->inner->set('key', 'original', 60);
        $adapter = new TracingCacheAdapter($this->inner, $this->noOpTracer());
        $adapter->setNx('key', 'overwrite', 60);

        $this->assertSame('original', $this->inner->get('key'));
    }

    public function test_set_nx_starts_span_with_correct_name(): void
    {
        $tracer = $this->createMock(TracingInterface::class);
        $tracer->expects($this->once())
            ->method('startSpan')
            ->with('cache.set_nx', $this->arrayHasKey('cache.key'))
            ->willReturn(new NoOpSpan());

        $adapter = new TracingCacheAdapter($this->inner, $tracer);
        $adapter->setNx('key', 'value', 60);
    }

    public function test_set_nx_ends_span_on_success(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects($this->once())->method('setStatus')->with('ok');
        $span->expects($this->once())->method('end');

        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('startSpan')->willReturn($span);

        $adapter = new TracingCacheAdapter($this->inner, $tracer);
        $adapter->setNx('key', 'value', 60);
    }

    public function test_set_nx_records_exception_and_rethrows(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects($this->once())->method('recordException');
        $span->expects($this->once())->method('setStatus')->with('error');
        $span->expects($this->once())->method('end');

        $tracer = $this->createMock(TracingInterface::class);
        $tracer->method('startSpan')->willReturn($span);

        $brokenInner = new ThrowingCacheAdapter();

        $adapter = new TracingCacheAdapter($brokenInner, $tracer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redis down');
        $adapter->setNx('key', 'value', 60);
    }

    public function test_implements_atomic_cache_interface(): void
    {
        $adapter = new TracingCacheAdapter($this->inner, $this->noOpTracer());

        $this->assertInstanceOf(\Vortos\Cache\Contract\AtomicCacheInterface::class, $adapter);
    }

    private function noOpTracer(): TracingInterface
    {
        return new \Vortos\Tracing\NoOpTracer();
    }
}

final class ThrowingCacheAdapter implements \Vortos\Cache\Contract\TaggedCacheInterface, \Vortos\Cache\Contract\AtomicCacheInterface
{
    public function get(string $key, mixed $default = null): mixed { return $default; }
    public function set(string $key, mixed $value, mixed $ttl = null): bool { return true; }
    public function delete(string $key): bool { return true; }
    public function clear(): bool { return true; }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
    public function setMultiple(iterable $values, mixed $ttl = null): bool { return true; }
    public function deleteMultiple(iterable $keys): bool { return true; }
    public function has(string $key): bool { return false; }
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool { return true; }
    public function invalidateTags(array $tags): bool { return true; }
    public function setNx(string $key, mixed $value, int $ttl): bool
    {
        throw new \RuntimeException('redis down');
    }
}
