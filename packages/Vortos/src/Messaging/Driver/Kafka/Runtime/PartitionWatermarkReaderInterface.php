<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

/**
 * Reads the high watermark (the offset of the next message to be produced) for one partition.
 *
 * Exists to isolate rdkafka's out-parameter API: queryWatermarkOffsets() returns its results
 * through by-reference arguments, which no ordinary test double can populate. Behind this interface
 * the lag arithmetic in {@see KafkaConsumer} is expressible — and testable — as a plain int.
 */
interface PartitionWatermarkReaderInterface
{
    /**
     * @return int|null The partition high watermark, or null when the broker did not answer within
     *                  the timeout. Null means "no sample this round", never "lag is zero".
     */
    public function highWatermark(string $topic, int $partition, int $timeoutMs): ?int;
}
