<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * @internal Used only by PersistenceMetricsDecorator
 */
final class PersistenceMetricsDriver extends AbstractDriverMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver $wrappedDriver,
        private readonly FrameworkTelemetry $telemetry,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        return new PersistenceMetricsConnection(parent::connect($params), $this->telemetry);
    }
}
