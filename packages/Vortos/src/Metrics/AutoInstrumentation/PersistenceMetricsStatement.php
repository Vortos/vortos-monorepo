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
            $this->metrics->histogram('db_query_duration_ms', ['driver' => 'dbal'])->observe($durationMs);
        }
    }
}
