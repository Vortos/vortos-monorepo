<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\State;

/**
 * Infra-less {@see EdgeStateStoreInterface} backed by a JSON file under a directory shared between the
 * deploy one-shot and the edge (e.g. /opt/vortos/edge). This replaces the old MountedConfigWriter
 * write to /etc/caddy/ (a path the one-shot could not reach). Suitable for a single-node edge; the
 * Redis driver is the default for a horizontally-scaled fleet.
 *
 * Writes are atomic (temp file + rename) and 0640 so the edge's group can read them without world
 * access. Version is monotonic per env.
 */
final class FileEdgeStateStore implements EdgeStateStoreInterface
{
    public function __construct(
        private readonly string $baseDir = '/opt/vortos/edge',
    ) {}

    public function load(string $env): ?EdgeState
    {
        $path = $this->pathFor($env);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return EdgeState::fromArray($decoded);
    }

    public function save(EdgeState $state): EdgeState
    {
        $current = $this->load($state->env);
        $nextVersion = ($current?->version ?? 0) + 1;
        $stamped = $state->withVersion($nextVersion, gmdate('c'));

        $path = $this->pathFor($state->env);
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create edge state directory: %s', $dir));
        }

        $json = json_encode($stamped->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException(sprintf('Cannot write edge state: %s', $tmp));
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot atomically persist edge state to %s', $path));
        }

        return $stamped;
    }

    private function pathFor(string $env): string
    {
        // Env names are operator-controlled identifiers; still normalize to a safe filename component
        // so a stray path separator can never escape the base dir.
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $env) ?? 'unknown';

        return rtrim($this->baseDir, '/') . '/state-' . $safe . '.json';
    }
}
