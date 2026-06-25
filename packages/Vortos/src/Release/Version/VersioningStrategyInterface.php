<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

interface VersioningStrategyInterface
{
    public function nextVersion(SemverVersion $current, BumpLevel $bump): SemverVersion;

    public function tagPrefix(): string;

    public function parseTag(string $tag): ?SemverVersion;
}
