<?php

declare(strict_types=1);

namespace Vortos\Messaging\DeadLetter;

use Vortos\Messaging\Contract\PayloadSanitizerInterface;

/**
 * Default no-op sanitizer. Payloads are stored as-is.
 * Replace with a custom implementation to strip PII before DLQ persistence.
 */
final class NullPayloadSanitizer implements PayloadSanitizerInterface
{
    public function sanitize(string $payload, array $headers): string
    {
        return $payload;
    }
}
