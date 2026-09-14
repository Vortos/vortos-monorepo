<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/**
 * Resolves a path the way compose does — relative to the topology's directory — without touching the
 * filesystem, so rules decide identically in CI and on the host.
 */
final class HostPath
{
    /**
     * @return string|null the normalised absolute path, or null when the path cannot be proven statically
     *                     (empty, interpolated, or traversing upwards)
     */
    public static function resolve(string $path, string $baseDir): ?string
    {
        if ($path === '' || str_contains($path, '$')) {
            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : rtrim($baseDir, '/') . '/' . $path;

        $segments = [];
        foreach (explode('/', $absolute) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
