<?php

declare(strict_types=1);

namespace Vortos\Payments\Webhook;

/**
 * An inbound webhook exactly as it arrived, before anything trusted it.
 *
 * ── Why all three shapes ──────────────────────────────────────────────────
 * Rails sign different things. Paddle signs the raw JSON body and carries the
 * signature in a header. PayHere posts form fields and signs a concatenation
 * of a few of them, with no header involved at all. A contract that assumed
 * either shape would force the other adapter to fake it — and the classic way
 * to fake "raw body" for a form post is to re-encode the parsed fields, which
 * silently normalises ordering and escaping and breaks verification in a way
 * that looks like a wrong secret.
 *
 * So the payload carries the body verbatim, the headers, and the parsed
 * fields, and each verifier reads whichever it actually signs.
 *
 * `rawBody` must be the untouched bytes. Anything that has been through a JSON
 * decode/encode round trip is not the raw body, however identical it looks.
 */
final readonly class SignedPayload
{
    /**
     * @param array<string, string> $headers Lower-cased header names.
     * @param array<string, string> $fields  Parsed form fields, for form-encoded rails.
     */
    public function __construct(
        public string $rawBody,
        public array  $headers = [],
        public array  $fields = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }
}
