<?php

declare(strict_types=1);

namespace Vortos\Alerts;

use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Notifier\NotificationResult;

final readonly class DispatchResult
{
    /** @param list<NotificationResult> $results */
    public function __construct(
        public DedupeDecision $decision,
        public array $results,
    ) {}
}
