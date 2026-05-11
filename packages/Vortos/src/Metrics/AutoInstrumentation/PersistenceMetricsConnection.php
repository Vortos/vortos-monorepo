<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * @internal Used only by PersistenceMetricsDriver
 */
final class PersistenceMetricsConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $wrappedConnection,
        private readonly MetricsInterface $metrics,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function query(string $sql): Result
    {
        $start = hrtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->record('query', $start);
        }
    }

    public function exec(string $sql): int
    {
        $start = hrtime(true);
        try {
            return parent::exec($sql);
        } finally {
            $this->record('execute', $start);
        }
    }

    public function prepare(string $sql): Statement
    {
        return new PersistenceMetricsStatement(parent::prepare($sql), $this->metrics);
    }

    private function record(string $operation, int $start): void
    {
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->metrics->counter('db_queries_total', ['driver' => 'dbal', 'operation' => $operation])->increment();
        $this->metrics->histogram('db_query_duration_ms', ['driver' => 'dbal'])->observe($durationMs);
    }
}
