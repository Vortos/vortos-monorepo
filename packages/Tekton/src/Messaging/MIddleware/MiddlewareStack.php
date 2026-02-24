<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware;

use Symfony\Component\Messenger\Envelope;

/**
 * Executes an ordered chain of MiddlewareInterface implementations around a handler.
 * Middlewares are stored in priority order (highest first). The stack wraps them
 * inside-out so the highest priority middleware is the outermost — it runs first
 * on the way in and last on the way out. The innermost callable is the actual
 * event handler.
 * Populated by MiddlewareCompilerPass at container compile time.
 */
final class MiddlewareStack
{
    public function __construct(
        private readonly array $middlewares = []
    ){
    }

    public function process(Envelope $envelope, callable $handler):Envelope
    {
        $chain = array_reduce(
            array_reverse($this->middlewares),
            fn(callable $next, MiddlewareInterface $middleware)
            => fn(Envelope $e) => $middleware->handle($e, $next) ,
            $handler
        );

        return $chain($envelope);
    }
}
