<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

enum NotificationType: string
{
    case FlagExpiryReminder     = 'notification.flag_expiry_reminder';
    case StaleFlagNag           = 'notification.stale_flag_nag';
    case ApprovalRequired       = 'notification.approval_required';
    case GuardrailRollback      = 'notification.guardrail_rollback';
    case DriftDetected          = 'notification.drift_detected';
}
