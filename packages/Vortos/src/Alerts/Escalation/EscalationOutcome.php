<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

enum EscalationOutcome: string
{
    /** Notify the responder at `EscalationDecision::$tier` (initial page or re-page). */
    case Notify = 'notify';
    /** Still within the current tier's wait window — no action. */
    case Wait = 'wait';
    /** Quiet hours (non-critical) or an active maintenance silence. */
    case Suppress = 'suppress';
    /** Acknowledged, or escalation exhausted every tier. */
    case Stop = 'stop';
}
