<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

final readonly class DriftEntry
{
    /** @param array<string, array{declared: mixed, effective: mixed}> $fields */
    public function __construct(
        public string $flagName,
        public DriftType $type,
        public string $details,
        public array $fields = [],
    ) {}

    public function toArray(): array
    {
        $data = [
            'flag'    => $this->flagName,
            'type'    => $this->type->value,
            'details' => $this->details,
        ];

        if (count($this->fields) > 0) {
            $data['fields'] = $this->fields;
        }

        return $data;
    }
}
