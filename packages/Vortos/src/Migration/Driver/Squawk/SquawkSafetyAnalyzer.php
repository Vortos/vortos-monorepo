<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\Squawk;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\MigrationSafetyCapability;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\SafetyResult;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('squawk')]
final class SquawkSafetyAnalyzer implements MigrationSafetyAnalyzerInterface
{
    private const RULE_MAP = [
        'prefer-robust-stmts' => ['ruleId' => 'pg.alter.blocking', 'severity' => Severity::Warning],
        'adding-serial-primary-key-field' => ['ruleId' => 'pg.column.volatile-default', 'severity' => Severity::Error],
        'adding-not-nullable-field' => ['ruleId' => 'pg.column.not-null-no-default', 'severity' => Severity::Error],
        'disallowed-unique-constraint' => ['ruleId' => 'pg.index.non-concurrent', 'severity' => Severity::Error],
        'require-concurrent-index-creation' => ['ruleId' => 'pg.index.non-concurrent', 'severity' => Severity::Error],
        'require-concurrent-index-deletion' => ['ruleId' => 'pg.index.non-concurrent', 'severity' => Severity::Error],
        'adding-foreign-key-constraint' => ['ruleId' => 'pg.alter.blocking', 'severity' => Severity::Error],
        'changing-column-type' => ['ruleId' => 'pg.alter.blocking', 'severity' => Severity::Error],
        'constraint-missing-not-valid' => ['ruleId' => 'pg.alter.blocking', 'severity' => Severity::Error],
        'setting-not-nullable-column' => ['ruleId' => 'pg.alter.blocking', 'severity' => Severity::Error],
    ];

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly string $binaryPath,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function analyze(MigrationArtifact $migration, ?TargetSchemaSnapshot $target): SafetyResult
    {
        $sql = implode(";\n", $migration->upSql);

        if (trim($sql) === '') {
            return SafetyResult::clean($this->engine());
        }

        if (!file_exists($this->binaryPath)) {
            return new SafetyResult($this->engine(), [
                new SafetyDiagnostic(
                    ruleId: 'squawk.binary-missing',
                    severity: Severity::Error,
                    table: null,
                    statementExcerpt: '',
                    message: sprintf('squawk binary not found at configured path: %s', $this->binaryPath),
                    remediation: 'Install squawk or update migration.safety.squawk.binary_path.',
                ),
            ]);
        }

        try {
            $result = $this->runner->run($this->binaryPath, $sql, $this->timeoutSeconds);
        } catch (\Throwable $e) {
            return new SafetyResult($this->engine(), [
                new SafetyDiagnostic(
                    ruleId: 'squawk.timeout',
                    severity: Severity::Error,
                    table: null,
                    statementExcerpt: '',
                    message: sprintf('squawk process failed: %s', $e->getMessage()),
                    remediation: 'Check squawk installation and increase migration.safety.squawk.timeout_ms if needed.',
                ),
            ]);
        }

        if ($result['exitCode'] !== 0 && trim($result['stdout']) === '') {
            return new SafetyResult($this->engine(), [
                new SafetyDiagnostic(
                    ruleId: 'squawk.exit-code',
                    severity: Severity::Error,
                    table: null,
                    statementExcerpt: '',
                    message: sprintf('squawk exited with code %d: %s', $result['exitCode'], substr($result['stderr'], 0, 200)),
                    remediation: 'Check squawk binary and SQL syntax.',
                ),
            ]);
        }

        return $this->parseOutput($result['stdout']);
    }

    public function engine(): string
    {
        return 'squawk';
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [
                MigrationSafetyCapability::AnalyzesLockSafety->key() => true,
                MigrationSafetyCapability::ReadsLiveTableStats->key() => false,
                MigrationSafetyCapability::VerifiesConcurrently->key() => true,
                MigrationSafetyCapability::UnderstandsExpandContract->key() => false,
            ],
            [
                'dialect' => 'postgres',
                'engine_version' => '1.0',
            ],
        );
    }

    private function parseOutput(string $stdout): SafetyResult
    {
        $decoded = json_decode($stdout, true);

        if (!is_array($decoded)) {
            return new SafetyResult($this->engine(), [
                new SafetyDiagnostic(
                    ruleId: 'squawk.malformed-json',
                    severity: Severity::Error,
                    table: null,
                    statementExcerpt: '',
                    message: 'squawk output is not valid JSON.',
                    remediation: 'Verify squawk version supports --reporter json.',
                ),
            ]);
        }

        $diagnostics = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ruleName = $item['RuleName'] ?? $item['rule_name'] ?? 'unknown';
            $mapped = self::RULE_MAP[$ruleName] ?? null;

            $diagnostics[] = new SafetyDiagnostic(
                ruleId: $mapped['ruleId'] ?? sprintf('squawk.%s', $ruleName),
                severity: $mapped['severity'] ?? Severity::Warning,
                table: null,
                statementExcerpt: (string) ($item['Sql'] ?? $item['sql'] ?? ''),
                message: (string) ($item['Description'] ?? $item['description'] ?? $ruleName),
                remediation: (string) ($item['Help'] ?? $item['help'] ?? ''),
            );
        }

        return new SafetyResult($this->engine(), $diagnostics);
    }
}
