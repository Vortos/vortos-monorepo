<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class VolatileDefaultRule implements SafetyRuleInterface
{
    private const VOLATILE_FUNCTIONS = [
        'now', 'current_timestamp', 'current_date', 'current_time',
        'clock_timestamp', 'statement_timestamp', 'transaction_timestamp',
        'timeofday', 'random', 'gen_random_uuid', 'uuid_generate_v4',
        'uuid_generate_v1', 'uuid_generate_v1mc',
        'nextval', 'currval', 'lastval',
    ];

    private const DEFAULT_ROW_THRESHOLD = 100_000;
    private const DEFAULT_BYTES_THRESHOLD = 67_108_864; // 64 MiB

    public function __construct(
        private readonly int $rowThreshold = self::DEFAULT_ROW_THRESHOLD,
        private readonly int $bytesThreshold = self::DEFAULT_BYTES_THRESHOLD,
    ) {}

    public function id(): string
    {
        return 'pg.column.volatile-default';
    }

    public function defaultSeverity(): Severity
    {
        return Severity::Error;
    }

    public function evaluate(
        MigrationArtifact $artifact,
        ?TargetSchemaSnapshot $target,
        ParsedStatement $statement,
    ): iterable {
        if (!$statement->matches('\bADD\s+(?:COLUMN\s+)?["`]?\w+["`]?\s+\w+.*\bDEFAULT\b')) {
            return;
        }

        $table = null;
        if (preg_match('/\bALTER\s+TABLE\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        if (preg_match('/\bDEFAULT\s+(.+?)(?:\s*(?:NOT\s+NULL|NULL|,|;|\)|$))/i', $statement->raw, $defaultMatch)) {
            $defaultExpr = trim($defaultMatch[1]);

            if ($this->isVolatile($defaultExpr)) {
                yield new SafetyDiagnostic(
                    ruleId: $this->id(),
                    severity: $this->defaultSeverity(),
                    table: $table,
                    statementExcerpt: $statement->raw,
                    message: sprintf(
                        'ADD COLUMN with volatile DEFAULT (%s) causes a full table rewrite on existing rows.',
                        $defaultExpr,
                    ),
                    remediation: 'Add the column as NULL first, then backfill in batches, then SET NOT NULL if needed. Or use #[AllowFullTableRewrite] if intentional.',
                    optOutAttribute: 'AllowFullTableRewrite',
                );

                return;
            }

            if ($table !== null && $this->isHotTable($table, $target)) {
                if ($artifact->hasAllowFullTableRewrite) {
                    return;
                }

                yield new SafetyDiagnostic(
                    ruleId: $this->id(),
                    severity: $this->defaultSeverity(),
                    table: $table,
                    statementExcerpt: $statement->raw,
                    message: sprintf(
                        'ADD COLUMN with DEFAULT on hot table "%s" (>%d rows or >%d bytes). PG11+ handles constant defaults as metadata-only, but without version confirmation this is flagged fail-closed.',
                        $table,
                        $this->rowThreshold,
                        $this->bytesThreshold,
                    ),
                    remediation: 'Confirm PG version >= 11 and that the default is a constant expression. Use #[AllowFullTableRewrite] to opt out.',
                    optOutAttribute: 'AllowFullTableRewrite',
                );
            }
        }
    }

    private function isVolatile(string $expr): bool
    {
        $normalized = strtolower(trim($expr, " \t\n\r\0\x0B'\""));

        foreach (self::VOLATILE_FUNCTIONS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $normalized)) {
                return true;
            }
        }

        if (preg_match('/\(\s*SELECT\b/i', $expr)) {
            return true;
        }

        return false;
    }

    private function isHotTable(string $table, ?TargetSchemaSnapshot $target): bool
    {
        if ($target === null) {
            return true;
        }

        return $target->isHot($table, $this->rowThreshold, $this->bytesThreshold);
    }
}
