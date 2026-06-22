<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

final readonly class DriftReport
{
    /** @param list<DriftEntry> $entries */
    public function __construct(
        public array $entries,
    ) {}

    public function hasDrift(): bool
    {
        return count($this->entries) > 0;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<DriftEntry> */
    public function ofType(DriftType $type): array
    {
        return array_values(array_filter(
            $this->entries,
            fn(DriftEntry $e) => $e->type === $type,
        ));
    }

    public function summary(): string
    {
        if (!$this->hasDrift()) {
            return 'No drift detected';
        }

        $mismatches  = count($this->ofType(DriftType::FieldMismatch));
        $missing     = count($this->ofType(DriftType::MissingInRuntime));
        $undeclared  = count($this->ofType(DriftType::UndeclaredInFile));

        $parts = [];
        if ($mismatches > 0) {
            $parts[] = "{$mismatches} field mismatch(es)";
        }
        if ($missing > 0) {
            $parts[] = "{$missing} missing in runtime";
        }
        if ($undeclared > 0) {
            $parts[] = "{$undeclared} undeclared in file";
        }

        return 'Drift detected: ' . implode(', ', $parts);
    }

    public function toArray(): array
    {
        return array_map(fn(DriftEntry $e) => $e->toArray(), $this->entries);
    }
}
