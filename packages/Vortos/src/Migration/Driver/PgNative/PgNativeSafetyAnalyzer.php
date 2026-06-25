<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\MigrationSafetyCapability;
use Vortos\Migration\Safety\SafetyResult;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleSet;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('pg-native')]
final class PgNativeSafetyAnalyzer implements MigrationSafetyAnalyzerInterface
{
    public function __construct(
        private readonly SafetyRuleSet $ruleSet,
    ) {}

    public function analyze(MigrationArtifact $migration, ?TargetSchemaSnapshot $target): SafetyResult
    {
        $diagnostics = [];

        foreach ($migration->upSql as $index => $sql) {
            $statement = new ParsedStatement($sql, $index);
            $diagnostics = array_merge(
                $diagnostics,
                $this->ruleSet->evaluate($migration, $target, $statement),
            );
        }

        return new SafetyResult($this->engine(), $diagnostics);
    }

    public function engine(): string
    {
        return 'pg-native';
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [
                MigrationSafetyCapability::AnalyzesLockSafety->key() => true,
                MigrationSafetyCapability::ReadsLiveTableStats->key() => true,
                MigrationSafetyCapability::VerifiesConcurrently->key() => true,
                MigrationSafetyCapability::UnderstandsExpandContract->key() => true,
            ],
            [
                'dialect' => 'postgres',
                'engine_version' => '1.0',
            ],
        );
    }
}
