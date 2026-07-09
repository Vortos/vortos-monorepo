<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * Port: read the edge's current live config (the admin API's in-memory config).
 *
 * Kept in the domain layer so drift/reconcile logic depends on the capability, not the concrete proxy
 * admin client.
 */
interface LiveConfigReaderInterface
{
    /** @return array<string, mixed> the live config, or [] when the edge has none loaded */
    public function currentConfig(): array;
}
