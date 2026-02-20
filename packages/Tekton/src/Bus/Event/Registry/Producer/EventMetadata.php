<?php

namespace Fortizan\Tekton\Bus\Event\Registry\Producer;

final readonly class EventMetadata
{
    public function __construct(
        public string $topic,
        public ?string $partitionKey = null,
        public string $version = 'v1',
        public ?string $channel = null,
        public array $extra = [] // For future stuff (compression, schema_id)
    ) {}
}