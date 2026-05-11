<?php

declare(strict_types=1);

namespace Vortos\Logger\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds stable ECS/OpenTelemetry-compatible fields to every log record.
 */
final class StructuredLogProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly string $serviceName,
        private readonly string $serviceVersion = '',
        private readonly string $deploymentEnvironment = '',
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            'ecs.version' => '8.11',
            'service.name' => $this->serviceName,
            ...($this->serviceVersion !== '' ? ['service.version' => $this->serviceVersion] : []),
            ...($this->deploymentEnvironment !== '' ? ['deployment.environment' => $this->deploymentEnvironment] : []),
            'event.dataset' => $record->channel,
            'log.logger' => $record->channel,
        ]);
    }
}
