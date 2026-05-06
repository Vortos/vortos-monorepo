<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

final class MigrationDriftReport
{
    public const Clean = 'clean';
    public const CompatibleExisting = 'compatible_existing';
    public const Partial = 'partial';
    public const Unknown = 'unknown';

    /**
     * @param string[] $existingTables
     * @param string[] $missingTables
     * @param string[] $existingIndexes
     * @param string[] $missingIndexes
     * @param array<string, string[]> $missingColumns
     */
    public function __construct(
        private readonly string $status,
        private readonly ?ModuleMigrationDescriptor $descriptor = null,
        private readonly array $existingTables = [],
        private readonly array $missingTables = [],
        private readonly array $existingIndexes = [],
        private readonly array $missingIndexes = [],
        private readonly array $missingColumns = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function descriptor(): ?ModuleMigrationDescriptor
    {
        return $this->descriptor;
    }

    public function hasDrift(): bool
    {
        return in_array($this->status, [self::CompatibleExisting, self::Partial, self::Unknown], true);
    }

    public function blocksMigration(): bool
    {
        return in_array($this->status, [self::CompatibleExisting, self::Partial], true);
    }

    /**
     * @return string[]
     */
    public function existingTables(): array
    {
        return $this->sorted($this->existingTables);
    }

    /**
     * @return string[]
     */
    public function missingTables(): array
    {
        return $this->sorted($this->missingTables);
    }

    /**
     * @return string[]
     */
    public function existingIndexes(): array
    {
        return $this->sorted($this->existingIndexes);
    }

    /**
     * @return string[]
     */
    public function missingIndexes(): array
    {
        return $this->sorted($this->missingIndexes);
    }

    /**
     * @return array<string, string[]>
     */
    public function missingColumns(): array
    {
        $columns = $this->missingColumns;
        ksort($columns);

        foreach ($columns as $table => $names) {
            sort($names);
            $columns[$table] = $names;
        }

        return $columns;
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
