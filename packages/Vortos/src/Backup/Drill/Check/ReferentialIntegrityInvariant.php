<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;

final class ReferentialIntegrityInvariant implements InvariantCheck
{
    public function name(): string
    {
        return 'referential_integrity';
    }

    public function check(array $connectionParams): InvariantResult
    {
        try {
            $pdo = $this->connect($connectionParams);

            $stmt = $pdo->query(<<<'SQL'
                SELECT tc.constraint_name, tc.table_name, kcu.column_name,
                       ccu.table_name AS foreign_table, ccu.column_name AS foreign_column
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu
                  ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            SQL);

            if ($stmt === false) {
                return InvariantResult::pass($this->name(), 'no FK constraints found');
            }

            $orphans = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fk) {
                $table = $fk['table_name'];
                $col = $fk['column_name'];
                $refTable = $fk['foreign_table'];
                $refCol = $fk['foreign_column'];

                $check = $pdo->query(sprintf(
                    'SELECT COUNT(*) FROM "%s" t WHERE t."%s" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM "%s" r WHERE r."%s" = t."%s")',
                    $table,
                    $col,
                    $refTable,
                    $refCol,
                    $col,
                ));
                $count = $check !== false ? (int) $check->fetchColumn() : 0;
                if ($count > 0) {
                    $orphans[] = sprintf('%s.%s → %s.%s: %d orphans', $table, $col, $refTable, $refCol, $count);
                }
            }

            if ($orphans !== []) {
                return InvariantResult::fail($this->name(), implode('; ', $orphans));
            }

            return InvariantResult::pass($this->name(), 'all FK constraints satisfied');
        } catch (\Throwable $e) {
            return InvariantResult::fail($this->name(), $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function connect(array $params): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $params['host'] ?? 'localhost',
            $params['port'] ?? '5432',
            $params['dbname'] ?? 'postgres',
        );

        return new \PDO($dsn, (string) ($params['user'] ?? 'postgres'), (string) ($params['password'] ?? ''));
    }
}
