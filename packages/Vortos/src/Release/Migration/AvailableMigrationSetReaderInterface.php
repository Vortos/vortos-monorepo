<?php

declare(strict_types=1);

namespace Vortos\Release\Migration;

use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Reads the set of migrations *available in the build* (discovered on disk),
 * as opposed to {@see AppliedMigrationSetReaderInterface} which reads what has
 * already run against a database. Recording a build manifest happens before its
 * migrations are applied, so the manifest's schema fingerprint must come from the
 * available set — it is the desired schema this build carries.
 */
interface AvailableMigrationSetReaderInterface
{
    public function availableSet(): SchemaFingerprint;
}
