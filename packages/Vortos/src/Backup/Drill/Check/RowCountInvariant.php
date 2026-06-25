<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;

final class RowCountInvariant implements InvariantCheck
{
    /** @var list<string> */
    private readonly array $tables;
    private readonly int $minRows;

    /**
     * @param list<string> $tables
     */
    public function __construct(array $tables = [], int $minRows = 1)
    {
        $this->tables = $tables;
        $this->minRows = $minRows;
    }

    public function name(): string
    {
        return 'row_count';
    }

    public function check(array $connectionParams): InvariantResult
    {
        if ($this->tables === []) {
            return InvariantResult::pass($this->name(), 'no tables configured');
        }

        try {
            $pdo = $this->connect($connectionParams);
            $failures = [];

            foreach ($this->tables as $table) {
                $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
                $stmt = $pdo->query("SELECT COUNT(*) FROM \"{$safeName}\"");
                $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

                if ($count < $this->minRows) {
                    $failures[] = sprintf('%s: %d rows (expected >= %d)', $table, $count, $this->minRows);
                }
            }

            if ($failures !== []) {
                return InvariantResult::fail($this->name(), implode('; ', $failures));
            }

            return InvariantResult::pass($this->name(), sprintf('%d tables checked', count($this->tables)));
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
