<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\InMemory\Definition;

use Vortos\Messaging\Definition\Consumer\AbstractConsumerDefinition;

/**
 * In-memory consumer definition for use in tests.
 * Paired with InMemoryTransportDefinition and InMemoryConsumer.
 */
final class InMemoryConsumerDefinition extends AbstractConsumerDefinition
{
    public function toArray(): array
    {
        return [
            'driver'      => 'in_memory',
            'transport'   => $this->transportName,
            'parallelism' => $this->parallelism,
            'batchSize'   => $this->batchSize,
            'retry'       => $this->retryPolicy,
            'dlq'         => $this->dlqTransport,
            'inProcess'   => true,
        ];  
    }
}