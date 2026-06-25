<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Architecture;

/**
 * One detected leak: a provider name appearing in a symbol outside an allowed
 * (`Driver/` or composition-root) location.
 */
final readonly class ProviderNameOccurrence
{
    public function __construct(
        public string $file,
        public int $line,
        public string $provider,
        public string $symbol,
    ) {}

    public function describe(): string
    {
        return sprintf(
            "%s:%d — provider name '%s' leaked in symbol '%s' (move it into a Driver\\ namespace or the composition root).",
            $this->file,
            $this->line,
            $this->provider,
            $this->symbol,
        );
    }
}
