<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;

final class SmokeQueryInvariant implements InvariantCheck
{
    public function __construct(
        private readonly string $query = 'SELECT 1',
    ) {}

    public function name(): string
    {
        return 'smoke_query';
    }

    public function check(array $connectionParams): InvariantResult
    {
        try {
            $pdo = $this->connect($connectionParams);
            $stmt = $pdo->query($this->query);

            if ($stmt === false) {
                return InvariantResult::fail($this->name(), 'query returned no result set');
            }

            $row = $stmt->fetch(\PDO::FETCH_NUM);
            if ($row === false) {
                return InvariantResult::fail($this->name(), 'query returned zero rows');
            }

            return InvariantResult::pass($this->name(), 'query returned data');
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
