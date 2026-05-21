<?php

declare(strict_types=1);

namespace Vortos\Messaging\Middleware\Core;

use Vortos\Messaging\Bus\Stamp\ConsumerStamp;
use Vortos\Messaging\Bus\Stamp\EventEnvelopeStamp;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Fires BeforeConsume and AfterConsume lifecycle hooks around handler execution.
 *
 * Extracts the EventEnvelopeStamp (attached by ConsumerRunner) so that hooks
 * receive a full domain EventEnvelope rather than the raw Messenger Envelope.
 * Falls back to a no-op when the stamp is absent — envelope is not from the
 * consumer pipeline in that case.
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

        $domainEnvelope = $envelope->last(EventEnvelopeStamp::class)?->envelope;

        if ($domainEnvelope === null) {
            return $next($envelope);
        }

        $consumerName = $consumerStamp->consumerName;

        $this->hookRunner->runBeforeConsume($domainEnvelope, $consumerName);

        try {
            $result = $next($envelope);
            $this->hookRunner->runAfterConsume($domainEnvelope, $consumerName, null);
            return $result;
        } catch (\Throwable $e) {
            $this->hookRunner->runAfterConsume($domainEnvelope, $consumerName, $e);
            throw $e;
        }
    }
}
