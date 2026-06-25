<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

use InvalidArgumentException;
use Vortos\Alerts\Severity;

/**
 * The rendered, channel-agnostic payload a driver maps to its backend's wire format.
 * Already scrubbed by construction (built from a scrubbed {@see \Vortos\Alerts\Event\AlertEvent}).
 */
final readonly class NotifierMessage
{
    /**
     * @param array<string, string> $fields      small key/value table (rule, env, tier…)
     * @param list<string>          $links
     */
    public function __construct(
        public string $idempotencyKey,
        public Severity $severity,
        public string $title,
        public string $body,
        public array $fields,
        public array $links,
        public ?string $runbookUrl = null,
    ) {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('NotifierMessage idempotencyKey must not be empty.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('NotifierMessage title must not be empty.');
        }
    }
}
