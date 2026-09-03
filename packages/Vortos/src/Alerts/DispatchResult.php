<?php

declare(strict_types=1);

namespace Vortos\Alerts;

use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Notifier\NotificationResult;

final readonly class DispatchResult
{
    /**
     * @param list<NotificationResult> $results
     * @param ?string $suppressedBy When set, the alert was NOT delivered because an inhibition rule's
     *        source (this rule id) is actively firing — one root cause, one page. The alert's state
     *        is still recorded as open; only the outbound notification is withheld. Null on the
     *        normal path.
     */
    public function __construct(
        public DedupeDecision $decision,
        public array $results,
        public ?string $suppressedBy = null,
    ) {}
}
