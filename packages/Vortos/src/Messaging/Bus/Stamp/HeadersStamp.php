<?php

declare(strict_types=1);

namespace Vortos\Messaging\Bus\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the raw Kafka message headers through the Symfony Messenger envelope.
 *
 * Attached to every envelope by ConsumerRunner before handlers are dispatched.
 * Used by ConsumerRunner::resolveArguments() to inject arbitrary header values
 * into handler parameters annotated with #[Header('header-name')].
 */
final readonly class HeadersStamp implements StampInterface
{
    public function __construct(
        public array $headers
    ){}
}
