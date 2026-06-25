<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final class AlphaCounterStrategy implements VersioningStrategyInterface
{
    private const TAG_PATTERN = '/^v?(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)-alpha-(?P<counter>\d+)$/';

    public function __construct(
        private readonly int $baseMajor = 1,
        private readonly int $baseMinor = 0,
        private readonly int $basePatch = 0,
    ) {}

    public function nextVersion(SemverVersion $current, BumpLevel $bump): SemverVersion
    {
        $counter = $this->extractCounter($current);

        return new SemverVersion(
            major: $this->baseMajor,
            minor: $this->baseMinor,
            patch: $this->basePatch,
            prerelease: 'alpha-' . ($counter + 1),
        );
    }

    public function tagPrefix(): string
    {
        return sprintf('v%d.%d.%d-alpha-', $this->baseMajor, $this->baseMinor, $this->basePatch);
    }

    public function parseTag(string $tag): ?SemverVersion
    {
        if (preg_match(self::TAG_PATTERN, $tag, $m) !== 1) {
            return null;
        }

        return new SemverVersion(
            major: (int) $m['major'],
            minor: (int) $m['minor'],
            patch: (int) $m['patch'],
            prerelease: 'alpha-' . $m['counter'],
        );
    }

    private function extractCounter(SemverVersion $version): int
    {
        if ($version->prerelease !== null && preg_match('/^alpha-(\d+)$/', $version->prerelease, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }
}
