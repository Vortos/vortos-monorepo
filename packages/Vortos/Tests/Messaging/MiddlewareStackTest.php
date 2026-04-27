<?php
declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Vortos\Messaging\Middleware\MiddlewareInterface;
use Vortos\Messaging\Middleware\MiddlewareStack;

final class OrderTrackingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $name,
        private array &$order
    ) {}

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        $this->order[] = $this->name;
        return $next($envelope);
    }
}

final class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, callable $next): Envelope
    {
        return $envelope; // does not call $next
    }
}

final class MiddlewareStackTest extends TestCase
{
    public function test_executes_middleware_in_order(): void
    {
        $order = [];
        $stack = new MiddlewareStack([
            new OrderTrackingMiddleware('first', $order),
            new OrderTrackingMiddleware('second', $order),
        ]);

        $envelope = new Envelope(new \stdClass());
        $stack->process($envelope, function(Envelope $e) use (&$order) {
            $order[] = 'handler';
            return $e;
        });

        $this->assertSame(['first', 'second', 'handler'], $order);
    }

    public function test_middleware_can_short_circuit(): void
    {
        $handlerCalled = false;
        $stack = new MiddlewareStack([new ShortCircuitMiddleware()]);
        $envelope = new Envelope(new \stdClass());

        $stack->process($envelope, function(Envelope $e) use (&$handlerCalled) {
            $handlerCalled = true;
            return $e;
        });

        $this->assertFalse($handlerCalled);
    }

    public function test_empty_stack_calls_handler_directly(): void
    {
        $handlerCalled = false;
        $stack = new MiddlewareStack([]);
        $envelope = new Envelope(new \stdClass());

        $stack->process($envelope, function(Envelope $e) use (&$handlerCalled) {
            $handlerCalled = true;
            return $e;
        });

        $this->assertTrue($handlerCalled);
    }
}
