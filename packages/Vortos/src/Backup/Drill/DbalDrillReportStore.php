<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Vortos\Backup\Domain\DatabaseEngine;

final class DbalDrillReportStore implements DrillReportStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function save(DrillReport $report): void
    {
        $this->connection->insert($this->table, [
            'id' => $report->id,
            'engine' => $report->engine->value,
            'environment' => $report->environment,
            'artifact_id' => $report->artifactId,
            'started_at' => $report->startedAt->format(DATE_ATOM),
            'rto_ms' => $report->rtoMs,
            'outcome' => $report->outcome,
            'invariants' => json_encode(
                array_map(static fn (InvariantResult $r): array => $r->toArray(), $report->invariants),
                JSON_THROW_ON_ERROR,
            ),
            'error' => $report->error,
        ]);
    }

    public function latest(string $engine, string $environment): ?DrillReport
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->setParameter('engine', $engine)
            ->setParameter('env', $environment)
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        /** @var list<array{name:string, passed:bool, detail:string}> $invariants */
        $invariants = json_decode((string) $row['invariants'], true) ?: [];

        return new DrillReport(
            (string) $row['id'],
            DatabaseEngine::from((string) $row['engine']),
            (string) $row['environment'],
            (string) $row['artifact_id'],
            new DateTimeImmutable((string) $row['started_at']),
            (int) $row['rto_ms'],
            (string) $row['outcome'],
            array_map(
                static fn (array $r): InvariantResult => $r['passed']
                    ? InvariantResult::pass($r['name'], $r['detail'] ?? '')
                    : InvariantResult::fail($r['name'], $r['detail'] ?? ''),
                $invariants,
            ),
            isset($row['error']) && $row['error'] !== '' ? (string) $row['error'] : null,
        );
    }
}
