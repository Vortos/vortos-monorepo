<?php

declare(strict_types=1);

namespace Vortos\Release\Migration;

use Vortos\Release\Schema\SchemaFingerprint;

interface AppliedMigrationSetReaderInterface
{
    public function currentApplied(): SchemaFingerprint;
}
