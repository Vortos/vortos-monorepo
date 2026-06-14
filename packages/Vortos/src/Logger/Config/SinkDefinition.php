<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

use Monolog\Level;

/**
 * A single log destination: where records go, at what level, how they're
 * buffered/flushed, retained, and (optionally) sampled or hash-chained.
 */
final class SinkDefinition
{
    /**
     * @param string|null $path For File: absolute or var/log-relative path. For Stream: PHP stream URI. For Custom: ignored.
     * @param string|null $customHandlerServiceId For Custom destinations: a DI service id implementing HandlerInterface.
     */
    public function __construct(
        public readonly string $id,
        public readonly SinkDestination $destination,
        public readonly ?string $path = null,
        public readonly Level $level = Level::Debug,
        public readonly BufferPolicy $bufferPolicy = BufferPolicy::Batched,
        public readonly RotationPolicy $rotation = new RotationPolicy(),
        public readonly ?int $sampleFactor = null,
        public readonly bool $hashChain = false,
        public readonly int $flushIntervalSeconds = 2,
        public readonly ?string $customHandlerServiceId = null,
    ) {}
}
