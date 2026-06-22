<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Vortos\FeatureFlags\FlagEnvironmentState;

/**
 * Port for per-environment flag state persistence (Block 10).
 *
 * The hot read path ({@see \Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver})
 * calls `findAllForEnv()` once per request (bulk load, no N+1). The write path
 * (exclusively {@see \Vortos\FeatureFlags\Application\FlagWriteService}) calls `save()`
 * after each mutation.
 */
interface FlagEnvironmentStateStorageInterface
{
    /**
     * Load the state for a single flag in a specific environment, or null when not
     * configured for that environment.
     */
    public function findForFlag(string $flagId, string $environment): ?FlagEnvironmentState;

    /**
     * Bulk-load all env states for an environment. The resolver calls this once per
     * request — every flag lookup for that scope is served from this single result.
     *
     * @return array<string, FlagEnvironmentState> keyed by flagId
     */
    public function findAllForEnv(string $environment): array;

    /**
     * Persist (upsert) a flag's env state. ON CONFLICT replaces the full row.
     *
     * @internal Route through {@see \Vortos\FeatureFlags\Application\FlagWriteService}.
     */
    public function save(FlagEnvironmentState $state): void;

    /**
     * Remove the env state for a flag in one environment (used when soft-archiving or
     * removing an environment-specific override).
     *
     * @internal Route through {@see \Vortos\FeatureFlags\Application\FlagWriteService}.
     */
    public function delete(string $flagId, string $environment): void;
}
