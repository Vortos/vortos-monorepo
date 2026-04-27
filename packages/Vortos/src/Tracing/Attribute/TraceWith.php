<?php
declare(strict_types=1);

namespace Vortos\Tracing\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class TraceWith
{
    public function __construct(
        public readonly float $sampleRate = 1.0,
        public readonly string $spanName = ''
    ) {}
}
