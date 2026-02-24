<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Bus\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the consumer name through the envelope during consumer-side processing.
 * Added by ConsumerRunner before dispatching a received message into the bus.
 * Read by LoggingMiddleware and TracingMiddleware to attach consumer context to logs and spans.
 */
final readonly class ConsumerStamp implements StampInterface
{
    public function __construct(
        public string $consumerName
    ){
    }
}