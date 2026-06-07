<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\Middleware\ObjectStoreMiddlewareStack;

final class ObjectStoreMiddlewareStackTest extends TestCase
{
    public function test_middlewares_wrap_driver_in_order(): void
    {
        $events = [];

        $first = new class($events) implements ObjectStoreMiddlewareInterface {
            public function __construct(private array &$events) {}
            public function process(ObjectStoreOperation $operation, callable $next): mixed
            {
                $this->events[] = 'first-before';
                $result = $next($operation);
                $this->events[] = 'first-after';
                return $result;
            }
        };

        $second = new class($events) implements ObjectStoreMiddlewareInterface {
            public function __construct(private array &$events) {}
            public function process(ObjectStoreOperation $operation, callable $next): mixed
            {
                $this->events[] = 'second-before';
                $result = $next($operation);
                $this->events[] = 'second-after';
                return $result;
            }
        };

        $driver = new class($events) extends NullObjectStore {
            public function __construct(private array &$events) {}
            public function put(\Vortos\ObjectStore\ValueObject\ObjectKey|string $key, mixed $body, ?\Vortos\ObjectStore\ValueObject\PutObjectOptions $options = null): \Vortos\ObjectStore\ValueObject\StoredObject
            {
                $this->events[] = 'driver';
                return parent::put($key, $body, $options);
            }
        };

        $stack = new ObjectStoreMiddlewareStack($driver, [$first, $second]);
        $stack->put('tmp/video.mp4', 'abc');

        $this->assertSame([
            'first-before',
            'second-before',
            'driver',
            'second-after',
            'first-after',
        ], $events);
    }
}
