<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

/**
 * Controls how a sink's records reach disk/stream.
 *
 *   WriteThrough — every record is written immediately. No data loss on
 *                   crash/SIGKILL. Use for Security/Audit sinks.
 *   Batched      — records are buffered in memory and flushed on a fixed
 *                   interval (FlushScheduler) or when the buffer fills.
 *                   Reduces I/O for high-volume sinks; bounded loss window
 *                   on crash equal to the flush interval.
 */
enum BufferPolicy
{
    case WriteThrough;
    case Batched;
}
