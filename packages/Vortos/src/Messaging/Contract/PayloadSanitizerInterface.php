<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * Sanitizes event payloads before they are persisted to the dead letter store.
 *
 * Bind your own implementation to strip or mask PII fields (email, card numbers,
 * health data, etc.) before they are written to vortos_failed_messages.
 *
 * The default implementation (NullPayloadSanitizer) is a no-op.
 */
interface PayloadSanitizerInterface
{
    /**
     * @param string $payload  Raw JSON event payload
     * @param array  $headers  Message headers (may contain correlation IDs, event class, etc.)
     *
     * @return string Sanitized payload — must remain valid JSON
     */
    public function sanitize(string $payload, array $headers): string;
}
