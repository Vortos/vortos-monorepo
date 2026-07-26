<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use RdKafka\KafkaConsumer as RdKafkaConsumer;
use Throwable;

/**
 * The production {@see PartitionWatermarkReaderInterface}, backed by the rdkafka extension.
 *
 * Swallows broker failures into a null sample: a watermark query is a monitoring read, and a broker
 * that is slow or briefly unreachable must not be able to interrupt consumption.
 */
final class RdKafkaWatermarkReader implements PartitionWatermarkReaderInterface
{
    public function __construct(private readonly RdKafkaConsumer $rdConsumer) {}

    public function highWatermark(string $topic, int $partition, int $timeoutMs): ?int
    {
        $low = 0;
        $high = 0;

        try {
            $this->rdConsumer->queryWatermarkOffsets($topic, $partition, $low, $high, $timeoutMs);
        } catch (Throwable) {
            return null;
        }

        return $high;
    }
}
