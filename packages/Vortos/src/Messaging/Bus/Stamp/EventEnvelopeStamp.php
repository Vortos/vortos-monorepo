<?php

declare(strict_types=1);

namespace Vortos\Messaging\Bus\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Vortos\Domain\Event\EventEnvelope;

/**
 * Carries the domain EventEnvelope through the Symfony Messenger pipeline.
 *
 * Attached to the Messenger Envelope by ConsumerRunner after reconstructing
 * the EventEnvelope from wire headers. Extracted by HookMiddleware so that
 * BeforeConsume/AfterConsume hooks receive a full EventEnvelope instead of
 * the raw Messenger Envelope.
 */
final readonly class EventEnvelopeStamp implements StampInterface
{
    public function __construct(
        public EventEnvelope $envelope,
    ) {}
}
