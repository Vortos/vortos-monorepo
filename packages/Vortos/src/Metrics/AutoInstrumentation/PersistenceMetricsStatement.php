<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * @internal Used only by PersistenceMetricsConnection
 */
final class PersistenceMetricsStatement extends AbstractStatementMiddleware
{
    private const DURATION_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500];

    public function __construct(
        Statement $wrappedStatement,
        private readonly MetricsInterface $metrics,
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        $start  = hrtime(true);
        try {
            return parent::execute();
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->metrics->counter('db_queries_total', ['driver' => 'dbal', 'operation' => 'execute'])->increment();
            $this->metrics->histogram('db_query_duration_ms', self::DURATION_BUCKETS, ['driver' => 'dbal'])->observe($durationMs);
        }
    }
}
