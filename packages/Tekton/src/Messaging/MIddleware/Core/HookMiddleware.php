<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware\Core;

use Fortizan\Tekton\Messaging\Bus\Stamp\ConsumerStamp;
use Fortizan\Tekton\Messaging\Hook\HookRunner;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Fires BeforeConsume and AfterConsume lifecycle hooks around handler execution.
 *
 * Sits between LoggingMiddleware and TransactionalMiddleware in the stack,
 * ensuring trace context is restored before hooks run and hook database
 * writes participate in the handler's transaction.
 *
 * Skips all hooks when ConsumerStamp is absent — envelope is not from
 * the consumer pipeline in that case.
 */
final class HookMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HookRunner $hookRunner
    ) {}

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        $consumerStamp = $envelope->last(ConsumerStamp::class);

        if ($consumerStamp === null) {
            return $next($envelope);
        }

        $consumerName = $consumerStamp->consumerName;

        $this->hookRunner->runBeforeConsume($envelope, $consumerName);

        try {
            $result = $next($envelope);
            $this->hookRunner->runAfterConsume($envelope, $consumerName, null);

            return $result;
        } catch (\Throwable $e) {
            $this->hookRunner->runAfterConsume($envelope, $consumerName, $e);
            throw $e;
        }
    }
}
