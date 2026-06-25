<?php

declare(strict_types=1);

namespace Vortos\Release\ReadModel;

use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\SchemaFingerprint;

interface ManifestReadModelInterface
{
    public function manifest(string $buildId): ?BuildManifest;

    public function latestForEnvironment(string $environment): ?BuildManifest;

    /**
     * The previous known-good build for an environment — the deploy immediately
     * before {@see latestForEnvironment()}. This is the default rollback target.
     * Returns null when there is no prior build (a first deploy cannot be rolled
     * back to a predecessor).
     */
    public function previousForEnvironment(string $environment): ?BuildManifest;

    public function currentApplied(string $environment): SchemaFingerprint;

    public function knownMigrationSet(): KnownMigrationSet;

    public function knownMigrationSetForEnvironment(string $environment): KnownMigrationSet;
}
