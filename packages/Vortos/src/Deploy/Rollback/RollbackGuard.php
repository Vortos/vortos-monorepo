<?php

declare(strict_types=1);

namespace Vortos\Deploy\Rollback;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Schema\RollbackInvariant;

final class RollbackGuard
{
    public function __construct(
        private readonly AppliedMigrationSetReaderInterface $appliedReader,
        private readonly ManifestReadModelInterface $manifestReadModel,
    ) {}

    /**
     * @throws RollbackRefusedException if rollback is not legal
     */
    public function assertLegal(BuildManifest $target, EnvironmentName $env): void
    {
        $applied = $this->appliedReader->currentApplied();
        $known = $this->manifestReadModel->knownMigrationSetForEnvironment($env->value);

        $decision = RollbackInvariant::evaluate(
            $target->schemaFingerprint,
            $applied,
            $known,
        );

        if (!$decision->legal) {
            throw new RollbackRefusedException($decision);
        }
    }

    /**
     * Pure variant for testing — no I/O.
     *
     * @throws RollbackRefusedException if rollback is not legal
     */
    public static function assertLegalPure(
        BuildManifest $target,
        \Vortos\Release\Schema\SchemaFingerprint $applied,
        \Vortos\Release\Schema\KnownMigrationSet $known,
    ): void {
        $decision = RollbackInvariant::evaluate(
            $target->schemaFingerprint,
            $applied,
            $known,
        );

        if (!$decision->legal) {
            throw new RollbackRefusedException($decision);
        }
    }
}
