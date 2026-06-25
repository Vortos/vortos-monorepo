<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Git\GitRepositoryInterface;

final class VersionSkewGuard
{
    public function __construct(
        private readonly GitRepositoryInterface $git,
        private readonly string $packagesBaseDir,
    ) {}

    /**
     * @param list<PackageInfo> $packages
     * @return list<string> Skewed package names (empty = all in sync)
     */
    public function detectSkew(array $packages): array
    {
        if (\count($packages) <= 1) {
            return [];
        }

        $treeShas = [];
        $skewed = [];

        foreach ($packages as $pkg) {
            $relativePath = $this->relativePath($pkg->path);

            try {
                $treeShas[$pkg->name] = $this->git->treeShaForPath($relativePath);
            } catch (\Throwable) {
                $skewed[] = $pkg->name;
            }
        }

        return $skewed;
    }

    private function relativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->packagesBaseDir)) {
            $relative = ltrim(substr($absolutePath, \strlen($this->packagesBaseDir)), '/');

            return $relative !== '' ? $relative : '.';
        }

        return $absolutePath;
    }
}
