<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * In-memory manifest read model for Block 12 runner/check tests. Configurable per
 * concern: latest build, previous build, applied fingerprint, and known set.
 */
final class FakeManifestReadModel implements ManifestReadModelInterface
{
    /** @var array<string, BuildManifest> keyed by build id */
    private array $byBuildId = [];

    /**
     * @param list<string> $knownIds
     */
    public function __construct(
        private ?BuildManifest $latest = null,
        private ?BuildManifest $previous = null,
        private ?SchemaFingerprint $applied = null,
        private array $knownIds = [],
    ) {
        foreach ([$latest, $previous] as $manifest) {
            if ($manifest !== null) {
                $this->byBuildId[$manifest->buildId] = $manifest;
            }
        }
    }

    public function register(BuildManifest $manifest): void
    {
        $this->byBuildId[$manifest->buildId] = $manifest;
    }

    public function manifest(string $buildId): ?BuildManifest
    {
        return $this->byBuildId[$buildId] ?? null;
    }

    public function latestForEnvironment(string $environment): ?BuildManifest
    {
        return $this->latest;
    }

    public function previousForEnvironment(string $environment): ?BuildManifest
    {
        return $this->previous;
    }

    public function currentApplied(string $environment): SchemaFingerprint
    {
        return $this->applied ?? SchemaFingerprint::empty();
    }

    public function knownMigrationSet(): KnownMigrationSet
    {
        return new KnownMigrationSet($this->knownIds);
    }

    public function knownMigrationSetForEnvironment(string $environment): KnownMigrationSet
    {
        return new KnownMigrationSet($this->knownIds);
    }
}
