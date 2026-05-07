<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * @internal Used only by PersistenceMetricsDecorator
 */
final class PersistenceMetricsDriver extends AbstractDriverMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver $wrappedDriver,
        private readonly MetricsInterface $metrics,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        return new PersistenceMetricsConnection(parent::connect($params), $this->metrics);
    }
}
