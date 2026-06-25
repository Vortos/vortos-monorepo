<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final class ConventionalSemverStrategy implements VersioningStrategyInterface
{
    public function nextVersion(SemverVersion $current, BumpLevel $bump): SemverVersion
    {
        if ($bump === BumpLevel::None) {
            return $current;
        }

        return $current->withBump($bump)->withPrerelease(null);
    }

    public function tagPrefix(): string
    {
        return 'v';
    }

    public function parseTag(string $tag): ?SemverVersion
    {
        try {
            return SemverVersion::parse($tag);
        } catch (InvalidVersionException) {
            return null;
        }
    }
}
