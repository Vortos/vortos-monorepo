<?php

declare(strict_types=1);

namespace Vortos\Release\Plan;

use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\SemverVersion;

final readonly class PackageTagPlan
{
    public function __construct(
        public string $packageName,
        public string $packagePath,
        public SemverVersion $currentVersion,
        public SemverVersion $nextVersion,
        public BumpLevel $bumpLevel,
        public string $commitRange,
        public Changelog $changelog,
        public string $remote,
    ) {}

    public function tagName(): string
    {
        return (string) $this->nextVersion;
    }

    public function hasChanges(): bool
    {
        return $this->bumpLevel !== BumpLevel::None;
    }

    public function render(): string
    {
        if (!$this->hasChanges()) {
            return sprintf('  %s: %s (no changes)', $this->packageName, $this->currentVersion);
        }

        $commitCount = $this->changelog->entries === [] ? 0 : \count($this->changelog->entries);

        return sprintf(
            '  %s: %s → %s (%s, %d commit%s)',
            $this->packageName,
            $this->currentVersion,
            $this->nextVersion,
            $this->bumpLevel->value,
            $commitCount,
            $commitCount === 1 ? '' : 's',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package_name' => $this->packageName,
            'package_path' => $this->packagePath,
            'current_version' => (string) $this->currentVersion,
            'next_version' => (string) $this->nextVersion,
            'bump_level' => $this->bumpLevel->value,
            'commit_range' => $this->commitRange,
            'tag_name' => $this->tagName(),
            'remote' => $this->remote,
            'changelog' => $this->changelog->toArray(),
        ];
    }
}
