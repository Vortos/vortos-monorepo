<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

final readonly class DefinitionDriftReport
{
    /** @param array<string, array{base: mixed, override: mixed}> $diffs */
    public function __construct(
        public string $environment,
        public array $diffs,
    ) {}

    public function hasDrift(): bool
    {
        return $this->diffs !== [];
    }

    /** @return list<string> */
    public function overriddenFields(): array
    {
        $fields = array_keys($this->diffs);
        sort($fields);

        return $fields;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'has_drift' => $this->hasDrift(),
            'diffs' => $this->diffs,
        ];
    }
}
