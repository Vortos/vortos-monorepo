<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

use Vortos\Auth\Audit\AuditEntry;

interface ChainStateStoreInterface
{
    /**
     * Atomically read the current chain tail (sequence + prevHash), invoke the
     * builder to produce a chained entry, and advance the stored state.
     *
     * @param callable(int $nextSequence, string $prevHash): AuditEntry $builder
     */
    public function appendChained(callable $builder): AuditEntry;
}
