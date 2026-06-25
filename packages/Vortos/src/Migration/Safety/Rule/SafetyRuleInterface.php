<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;

interface SafetyRuleInterface
{
    /** @return iterable<SafetyDiagnostic> */
    public function evaluate(
        MigrationArtifact $artifact,
        ?TargetSchemaSnapshot $target,
        ParsedStatement $statement,
    ): iterable;

    public function id(): string;

    public function defaultSeverity(): Severity;
}
