<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

use Vortos\Deploy\Exception\EdgeBaseConfigException;

/**
 * Resolves the operator's edge base config from disk, fail-closed.
 *
 * The base config travels with the app image (it is a file in the deploy repo, e.g.
 * docker/caddy/Caddyfile), so it is read from the deploy one-shot's own filesystem — not over SSH.
 * This resolver is the trust boundary for that read:
 *
 *  - An empty / unset path means the feature is OFF: returns null, and the caller keeps today's
 *    from-scratch generated config (backward compatible).
 *  - A configured-but-unreadable path is an ERROR: throws, so a typo never silently degrades to the
 *    generated path and a stale color.
 *  - The resolved real path MUST stay inside the project root. A symlink or ".." that escapes the
 *    root is refused (path-traversal / symlink-escape guard) — the base config is operator-owned, but
 *    the resolver still refuses to read arbitrary host files if the path is ever influenced.
 *  - The file is size-bounded before it is read into memory and handed to the adapter (DoS guard).
 */
final class EdgeBaseConfigResolver
{
    public const DEFAULT_MAX_BYTES = 1_048_576; // 1 MiB — a hand-written Caddyfile is kilobytes.

    public function __construct(
        private readonly string $projectRoot,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    /**
     * @param string|null $path relative (to project root) or absolute path; null/'' → feature off
     */
    public function resolve(?string $path): ?EdgeBaseConfig
    {
        if ($path === null || $path === '') {
            return null;
        }

        $rootReal = realpath($this->projectRoot);
        if ($rootReal === false) {
            throw EdgeBaseConfigException::unreadable($path, 'project root does not exist');
        }

        $candidate = $this->isAbsolute($path) ? $path : $rootReal . \DIRECTORY_SEPARATOR . $path;
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            throw EdgeBaseConfigException::unreadable($path, 'file not found or not a regular file');
        }

        // Containment: the resolved real path must live under the project root. Compare against the
        // root WITH a trailing separator so "/root-sibling" cannot masquerade as being under "/root".
        if ($real !== $rootReal && !str_starts_with($real, $rootReal . \DIRECTORY_SEPARATOR)) {
            throw EdgeBaseConfigException::pathEscape($path);
        }

        $size = filesize($real);
        if ($size === false) {
            throw EdgeBaseConfigException::unreadable($path, 'cannot stat file');
        }

        if ($size > $this->maxBytes) {
            throw EdgeBaseConfigException::tooLarge($path, $size, $this->maxBytes);
        }

        $contents = file_get_contents($real);
        if ($contents === false || $contents === '') {
            throw EdgeBaseConfigException::unreadable($path, 'file is empty or unreadable');
        }

        return new EdgeBaseConfig($real, $contents, EdgeConfigFormat::fromPath($real));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, \DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
