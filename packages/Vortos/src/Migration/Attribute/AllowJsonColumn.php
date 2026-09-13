<?php

declare(strict_types=1);

namespace Vortos\Migration\Attribute;

/**
 * Opts a migration out of `pg.type.json` for a column that genuinely needs JSON rather than JSONB.
 *
 * JSONB is the right default and the rule enforces it. The one real reason to keep JSON is that it
 * stores the exact text it was given — key order, whitespace, duplicate keys — so a column holding
 * a payload whose signature is re-verified from storage, or a document that must round-trip byte
 * for byte, can need it. The reason is required so the exception explains itself to whoever reads
 * the migration next.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AllowJsonColumn
{
    public function __construct(
        public string $reason,
    ) {}
}
