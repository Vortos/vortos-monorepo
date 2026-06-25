<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

final readonly class KnownMigrationSet
{
    /** @var list<string> */
    public array $ids;

    /** @param array<string> $ids All migration IDs known across every recorded manifest */
    public function __construct(array $ids)
    {
        $unique = array_values(array_unique($ids));
        sort($unique);
        $this->ids = $unique;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function contains(string $id): bool
    {
        return \in_array($id, $this->ids, true);
    }

    /** @return list<string> IDs in $fingerprint not present in this known set */
    public function unknownsIn(SchemaFingerprint $fingerprint): array
    {
        $unknowns = [];
        foreach ($fingerprint->migrationIds as $id) {
            if (!$this->contains($id)) {
                $unknowns[] = $id;
            }
        }

        return $unknowns;
    }
}
