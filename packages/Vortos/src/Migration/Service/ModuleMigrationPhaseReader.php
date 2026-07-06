<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Safety\DestructiveSqlDetector;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

/**
 * Resolves the deploy phase (Expand/Contract) of a migration for the deploy-runtime contract
 * guard.
 *
 * R7-3 hardening: this is a TOTAL function — it never throws. The previous implementation threw
 * InvalidArgumentException for any ID not present in the module registry, which meant a normal
 * pending app migration (`App\Migrations\Version*`, i.e. every published module stub and every
 * hand-written app migration) crashed the deploy preflight. It now classifies any migration:
 *
 *   1. Explicit #[DeployPhase] attribute  → that phase (author intent wins).
 *   2. #[AllowFullTableRewrite] opt-out    → Expand (author explicitly accepted the rewrite).
 *   3. Static destructive-SQL detection    → Contract if the up-SQL contains destructive DDL
 *                                            (DROP/RENAME/TYPE/SET NOT NULL/TRUNCATE …), so an
 *                                            un-annotated destructive migration is blocked at
 *                                            deploy time, not just in CI.
 *   4. Otherwise                           → Expand (safe default).
 *
 * An ID that resolves to no loadable class degrades to the safe default (Expand) — a deploy must
 * never die because it cannot name a migration.
 */
final class ModuleMigrationPhaseReader implements MigrationPhaseReaderInterface
{
    /** @var array<string, MigrationPhase> */
    private array $cache = [];

    public function __construct(
        private readonly ModuleMigrationRegistryInterface $registry,
        private readonly ?MigrationArtifactFactoryInterface $artifactFactory = null,
        private readonly DestructiveSqlDetector $detector = new DestructiveSqlDetector(),
    ) {}

    public function phaseOf(string $migrationId): MigrationPhase
    {
        if (!isset($this->cache[$migrationId])) {
            $this->cache[$migrationId] = $this->resolve($migrationId);
        }

        return $this->cache[$migrationId];
    }

    /**
     * @param list<string> $ids
     * @return array<string, MigrationPhase>
     */
    public function phasesFor(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            $result[$id] = $this->phaseOf($id);
        }

        return $result;
    }

    /**
     * True when the migration is destructive but carries no #[DeployPhase] declaration — the
     * case that must be surfaced with a precise remediation (annotate Contract + soak, or
     * Expand + #[AllowFullTableRewrite] if intentional) rather than a generic contract error.
     */
    public function isDestructiveAndUnannotated(string $migrationId): bool
    {
        $artifact = $this->artifactFactory?->fromClass($migrationId);

        if ($artifact === null || $artifact->phase !== null || $artifact->hasAllowFullTableRewrite) {
            return false;
        }

        return $this->detector->anyDestructive($artifact->upSql);
    }

    private function resolve(string $migrationId): MigrationPhase
    {
        // 1. Explicit attribute via the registry descriptor (module migrations) …
        if ($this->registry->descriptorForClass($migrationId) !== null) {
            $attributePhase = $this->attributePhase($migrationId);
            if ($attributePhase !== null) {
                return $attributePhase;
            }
        }

        // … or via the artifact factory (app migrations, and module migrations without a
        // descriptor hit). fromClass() reads the attribute, the opt-out, and the up-SQL.
        $artifact = $this->artifactFactory?->fromClass($migrationId);

        if ($artifact !== null) {
            if ($artifact->phase !== null) {
                return $artifact->phase;
            }

            if ($artifact->hasAllowFullTableRewrite) {
                return MigrationPhase::Expand;
            }

            if ($this->detector->anyDestructive($artifact->upSql)) {
                return MigrationPhase::Contract;
            }

            return MigrationPhase::Expand;
        }

        // No factory wired — fall back to reflection-only attribute lookup, else safe default.
        return $this->attributePhase($migrationId) ?? MigrationPhase::safeDefault();
    }

    private function attributePhase(string $class): ?MigrationPhase
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return null;
        }

        $attributes = $reflection->getAttributes(DeployPhase::class);

        if ($attributes === []) {
            return null;
        }

        /** @var DeployPhase $attr */
        $attr = $attributes[0]->newInstance();

        return $attr->phase;
    }
}
