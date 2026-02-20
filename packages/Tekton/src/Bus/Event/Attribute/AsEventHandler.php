<?php

namespace Fortizan\Tekton\Bus\Event\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AsEventHandler
{
    public function __construct(
        /**
         * The logical name of the Consumer (Worker Fleet).
         * Must match a name defined in your ConsumerConfig.
         */
        public string $consumer,

        /**
         * Execution priority within this consumer.
         * Higher = runs first.
         */
        public int $priority = 0,

        /**
         * Is this handler safe to retry? 
         * Allows middleware to skip de-duplication checks.
         */
        public bool $idempotent = false,

        /**
         * Optional: Filter by event schema version.
         */
        public int $version = 1,
    ){
    }
}