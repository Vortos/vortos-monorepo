<?php

declare(strict_types=1);

namespace Vortos\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects an arbitrary header value from the raw message headers into the marked handler parameter.
 *
 * Use this when you need a header that is not covered by the built-in #[MessageId],
 * #[CorrelationId], or #[Timestamp] attributes. The value is extracted from HeadersStamp
 * on the Symfony Messenger envelope and resolved at dispatch time by ConsumerRunner.
 * Returns null if the header is not present.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[Header('x-tenant-id')] ?string $tenantId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Header
{
    public function __construct(public string $name) {}
}
