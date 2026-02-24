<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware;

use Symfony\Component\Messenger\Envelope;

/**
 * Cross-cutting concern applied to every event dispatched through the bus.
 * Implement this interface and tag your service 'tekton.middleware' with a
 * 'priority' tag attribute. Higher priority runs first (outermost in the stack).
 * Always call $next($envelope) unless intentionally short-circuiting.
 * Example tag: ->tag('tekton.middleware', ['priority' => 1000])
 */
interface MiddlewareInterface
{
    public function handle(Envelope $envelope, callable $next): Envelope;
}