<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Vortos\FeatureFlags\FeatureFlag;

interface FlagStorageInterface
{
    /** @return FeatureFlag[] */
    public function findAll(): array;

    public function findByName(string $name): ?FeatureFlag;

    /**
     * Persist a flag's state.
     *
     * @internal Mutations must go through {@see \Vortos\FeatureFlags\Application\FlagWriteService}
     *           so every change is audited via the ledger. Calling this directly bypasses the
     *           audit trail; an architecture test fails the build for any caller outside the
     *           write service. (Read paths use findAll()/findByName().)
     */
    public function save(FeatureFlag $flag): void;

    /**
     * Delete a flag's row.
     *
     * @internal See {@see save()} — route deletions through
     *           {@see \Vortos\FeatureFlags\Application\FlagWriteService::archiveAndDelete()}.
     */
    public function delete(string $name): void;
}
