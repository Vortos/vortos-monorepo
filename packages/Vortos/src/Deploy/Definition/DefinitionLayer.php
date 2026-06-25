<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

final readonly class DefinitionLayer
{
    /** @param array<string, mixed> $overrides */
    public function __construct(
        public string $source,
        public array $overrides,
    ) {}
}
