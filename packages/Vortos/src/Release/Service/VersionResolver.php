<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Version\SemverVersion;
use Vortos\Release\Version\VersioningStrategyInterface;

final class VersionResolver
{
    public function __construct(
        private readonly GitRepositoryInterface $git,
        private readonly VersioningStrategyInterface $strategy,
    ) {}

    public function currentVersion(): SemverVersion
    {
        $prefix = $this->strategy->tagPrefix();
        $tags = $this->git->tagsMatching($prefix);

        $highest = null;

        foreach ($tags as $tag) {
            $parsed = $this->strategy->parseTag($tag);
            if ($parsed === null) {
                continue;
            }

            if ($highest === null || $parsed->greaterThan($highest)) {
                $highest = $parsed;
            }
        }

        return $highest ?? new SemverVersion(0, 0, 0);
    }

    public function latestTag(): ?string
    {
        $prefix = $this->strategy->tagPrefix();
        $tags = $this->git->tagsMatching($prefix);

        if ($tags === []) {
            return null;
        }

        $highest = null;
        $highestTag = null;

        foreach ($tags as $tag) {
            $parsed = $this->strategy->parseTag($tag);
            if ($parsed === null) {
                continue;
            }

            if ($highest === null || $parsed->greaterThan($highest)) {
                $highest = $parsed;
                $highestTag = $tag;
            }
        }

        return $highestTag;
    }
}
