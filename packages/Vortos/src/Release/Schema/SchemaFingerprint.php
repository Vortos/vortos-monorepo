<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

final readonly class SchemaFingerprint
{
    /** @var list<string> Sorted, deduplicated migration IDs */
    public array $migrationIds;

    public string $hash;

    /** @var array<string, true> O(1) lookup set */
    private array $lookupSet;

    /** @param array<string> $migrationIds */
    public function __construct(array $migrationIds)
    {
        $unique = array_values(array_unique($migrationIds));
        sort($unique);

        $this->migrationIds = $unique;
        $this->hash = 'sha256:' . hash('sha256', implode("\n", $this->migrationIds));

        $set = [];
        foreach ($this->migrationIds as $id) {
            $set[$id] = true;
        }
        $this->lookupSet = $set;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->migrationIds === [];
    }

    public function count(): int
    {
        return \count($this->migrationIds);
    }

    public function contains(string $migrationId): bool
    {
        return isset($this->lookupSet[$migrationId]);
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    public function isSubsetOf(self $other): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        foreach ($this->migrationIds as $id) {
            if (!$other->contains($id)) {
                return false;
            }
        }

        return true;
    }

    public function isSupersetOf(self $other): bool
    {
        return $other->isSubsetOf($this);
    }

    public function relationTo(self $other): FingerprintRelation
    {
        $isSubset = $this->isSubsetOf($other);
        $isSuperset = $this->isSupersetOf($other);

        if ($isSubset && $isSuperset) {
            return FingerprintRelation::Equal;
        }

        if ($isSubset) {
            return FingerprintRelation::Subset;
        }

        if ($isSuperset) {
            return FingerprintRelation::Superset;
        }

        $hasOverlap = false;
        foreach ($this->migrationIds as $id) {
            if ($other->contains($id)) {
                $hasOverlap = true;
                break;
            }
        }

        return $hasOverlap ? FingerprintRelation::Overlapping : FingerprintRelation::Disjoint;
    }

    /** @return list<string> IDs present in $this but missing from $applied */
    public function missingFrom(self $applied): array
    {
        $missing = [];
        foreach ($this->migrationIds as $id) {
            if (!$applied->contains($id)) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{hash: string, migration_ids: list<string>} */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'migration_ids' => $this->migrationIds,
        ];
    }

    /** @param array{hash?: string, migration_ids: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['migration_ids']);
    }
}
