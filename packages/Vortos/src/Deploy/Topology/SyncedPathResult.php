<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

/**
 * What syncing one declared path found and did. Names files, never contents.
 */
final readonly class SyncedPathResult
{
    /**
     * @param list<string>           $changedFiles project-relative files whose content, mode or owner differed
     * @param list<string>           $removedFiles project-relative host files a synced directory no longer ships
     * @param non-empty-list<string> $services     the services that mount this path
     */
    public function __construct(
        public string $path,
        public SyncedPathStatus $status,
        public bool $applied,
        public bool $isFile,
        public array $changedFiles,
        public array $removedFiles,
        public array $services,
        public ?string $backupDir,
    ) {}

    /**
     * Whether the services mounting this path must be recreated to see the new copy.
     *
     * Only for a single-file bind. The file is replaced by rename(2) so no reader ever sees it half
     * written — and a running container's bind mount keeps pointing at the old inode, so it goes on
     * reading the previous version until it is recreated. A directory bind sees renamed entries
     * immediately, because the directory itself is what is mounted.
     */
    public function needsRecreate(): bool
    {
        return $this->applied && $this->isFile && $this->status === SyncedPathStatus::Updated && $this->changedFiles !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status->value,
            'applied' => $this->applied,
            'changed_files' => $this->changedFiles,
            'removed_files' => $this->removedFiles,
            'services' => $this->services,
            'backup_dir' => $this->backupDir,
            'needs_recreate' => $this->needsRecreate(),
        ];
    }
}
