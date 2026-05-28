<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Contract\ObjectStoreObserverInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Middleware\HookMiddleware;

final class HookMiddlewareTest extends TestCase
{
    public function test_observer_receives_before_and_after(): void
    {
        $events = [];
        $observer = new class($events) implements ObjectStoreObserverInterface {
            public function __construct(private array &$events) {}
            public function before(ObjectStoreOperation $operation): void { $this->events[] = 'before:' . $operation->name(); }
            public function after(ObjectStoreOperation $operation, mixed $result): void { $this->events[] = 'after:' . $result; }
            public function failed(ObjectStoreOperation $operation, \Throwable $error): void { $this->events[] = 'failed'; }
        };

        $middleware = new HookMiddleware([$observer], new NullLogger());
        $result = $middleware->process(new ObjectStoreOperation('put'), static fn() => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(['before:put', 'after:ok'], $events);
    }

    public function test_observer_receives_failure(): void
    {
        $events = [];
        $observer = new class($events) implements ObjectStoreObserverInterface {
            public function __construct(private array &$events) {}
            public function before(ObjectStoreOperation $operation): void { $this->events[] = 'before'; }
            public function after(ObjectStoreOperation $operation, mixed $result): void { $this->events[] = 'after'; }
            public function failed(ObjectStoreOperation $operation, \Throwable $error): void { $this->events[] = 'failed:' . $error->getMessage(); }
        };

        $middleware = new HookMiddleware([$observer], new NullLogger());

        try {
            $middleware->process(new ObjectStoreOperation('put'), static fn() => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(['before', 'failed:boom'], $events);
    }
}
