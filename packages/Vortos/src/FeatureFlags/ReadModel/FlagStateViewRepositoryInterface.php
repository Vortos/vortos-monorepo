<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

/**
 * Read repository (port) for the current-flag-state view.
 * Keyed by (environment, flag_name) since Block 10.
 */
interface FlagStateViewRepositoryInterface
{
    /** Idempotent upsert (keyed by environment + flag name). */
    public function upsert(FlagStateView $view): void;

    public function findByName(string $flagName, string $environment = 'production'): ?FlagStateView;

    /**
     * @return list<FlagStateView>
     */
    public function all(string $environment = 'production', int $limit = 500): array;
}
