<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * The single definition of how a migration stub is identified in the publish manifest.
 *
 * WHY THIS IS ONE CLASS AND NOT TWO METHODS
 * -----------------------------------------
 * The publisher decides what to WRITE and the detector decides what counts as already published.
 * They had separate copies of the rule, and the moment the writer changed the reader disagreed:
 * auto-publish emitted a migration, then immediately reported that same stub as unpublished and
 * refused the deploy. Two components disagreeing about identity is the whole bug, so identity now
 * lives in one place that both call.
 *
 * CANONICAL FORM: module plus filename. The key used to be the stub's path relative to the project
 * directory, which silently made the manifest specific to one working directory — publish from a
 * container mounted elsewhere and NOTHING matched, so every already-published migration looked new
 * and the tool regenerated all of them. That produced 53 duplicate migrations for schema already
 * live in production. Module and filename are the stub's actual identity: stable across checkouts,
 * containers and mount points, and unique because two packages cannot own the same module.
 */
final class PublishManifestKey
{
    /**
     * The canonical key a newly published stub is recorded under.
     *
     * @param array{module: string, filename: string} $stub
     */
    public static function canonical(array $stub): string
    {
        return $stub['module'] . '/' . $stub['filename'];
    }

    /**
     * The key under which this stub is already recorded, or null if it is genuinely unpublished.
     *
     * Legacy forms are honoured deliberately. Treating an already-published stub as new is the
     * destructive direction — it regenerates migrations for live schema — so every historical key
     * shape stays recognised, and entries migrate to the canonical form on the next publish that
     * touches them.
     *
     * @param array{module: string, filename: string, relative?: string, is_provider?: bool, provider?: mixed} $stub
     * @param array<string, mixed> $manifest
     */
    public static function resolve(array $stub, array $manifest): ?string
    {
        $canonical = self::canonical($stub);

        if (isset($manifest[$canonical])) {
            return $canonical;
        }

        $relative = $stub['relative'] ?? null;

        if (is_string($relative) && isset($manifest[$relative])) {
            return $relative;
        }

        // A schema provider may still be recorded under the .sql stub it superseded.
        $isProvider = ($stub['is_provider'] ?? false) === true || isset($stub['provider']);

        if ($isProvider && is_string($relative)) {
            $legacySql = preg_replace('/\.[^.]+$/', '.sql', $relative) ?? $relative;

            if (isset($manifest[$legacySql])) {
                return $legacySql;
            }
        }

        // Oldest form: an absolute path with the leading slash trimmed, which varied with the
        // container's mount point. Matched on the tail so those remain recognised.
        $suffix = '/' . $stub['module'] . '/' . $stub['filename'];

        foreach (array_keys($manifest) as $existing) {
            $existing = (string) $existing;

            if (str_ends_with($existing, $suffix) || str_ends_with($existing, '/' . $stub['filename'])) {
                return $existing;
            }
        }

        return null;
    }
}
