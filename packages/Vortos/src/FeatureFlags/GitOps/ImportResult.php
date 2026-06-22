<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

final readonly class ImportResult
{
    /** @param list<string> $created @param list<string> $updated @param list<string> $deleted */
    public function __construct(
        public array $created,
        public array $updated,
        public array $deleted,
        public bool $dryRun,
    ) {}

    public function hasChanges(): bool
    {
        return count($this->created) > 0 || count($this->updated) > 0 || count($this->deleted) > 0;
    }

    public function summary(): string
    {
        $prefix = $this->dryRun ? '[dry-run] ' : '';

        return sprintf(
            '%s%d created, %d updated, %d deleted',
            $prefix,
            count($this->created),
            count($this->updated),
            count($this->deleted),
        );
    }

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'dry_run' => $this->dryRun,
        ];
    }
}
