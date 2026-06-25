<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Rule\SafetyRuleInterface;

final class ConcurrentInTransactionRule implements SafetyRuleInterface
{
    public function id(): string
    {
        return 'pg.index.concurrent-in-txn';
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
        if (!$statement->matches('\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+CONCURRENTLY\b')) {
            return;
        }

        if ($artifact->className === null) {
            return;
        }

        if (!$this->isTransactional($artifact->className)) {
            return;
        }

        $table = null;
        if (preg_match('/\bON\s+["`]?(\w+)["`]?/i', $statement->raw, $m)) {
            $table = strtolower($m[1]);
        }

        yield new SafetyDiagnostic(
            ruleId: $this->id(),
            severity: $this->defaultSeverity(),
            table: $table,
            statementExcerpt: $statement->raw,
            message: 'CREATE INDEX CONCURRENTLY cannot run inside a transaction.',
            remediation: 'Override isTransactional() to return false in the migration class, or use the Doctrine attribute #[Transactional(false)].',
        );
    }

    private function isTransactional(string $className): bool
    {
        if (!class_exists($className)) {
            return true;
        }

        try {
            $ref = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return true;
        }

        if ($ref->hasMethod('isTransactional')) {
            try {
                $instance = $ref->newInstanceWithoutConstructor();
                $method = $ref->getMethod('isTransactional');

                return (bool) $method->invoke($instance);
            } catch (\Throwable) {
                return true;
            }
        }

        return true;
    }
}
