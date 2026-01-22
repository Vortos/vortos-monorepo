<?php

namespace Fortizan\Tekton\Bus\Event\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class EventHandler
{
    public function __construct(
        /**
         * The Consumer Group ID (Required).
         * This determines which "Worker Fleet" runs this code.
         * Example: 'billing_service', 'email_worker'
         */
        public string $group,

        /**
         * How many times to retry on failure (Default: 3).
         * 0 = Fail immediately.
         * -1 = Retry forever (Dangerous!).
         */
        public int $retries = 3,

        /**
         * Time to wait before the first retry in milliseconds (Default: 1000ms).
         */
        public int $delay = 1000,

        /**
         * Backoff Strategy: 'fixed' or 'exponential' (Default: 'fixed').
         * 'fixed': 1s -> 1s -> 1s
         * 'exponential': 1s -> 2s -> 4s -> 8s
         */
        public string $backoff = 'fixed',

        /**
         * Dead Letter Queue Topic (Optional).
         * If null, the framework uses the default DLQ for the group (e.g. 'billing_service.dlq').
         * Useful if this specific handler is critical and needs a special graveyard.
         */
        public ?string $dlq = null
    ){
    }
}