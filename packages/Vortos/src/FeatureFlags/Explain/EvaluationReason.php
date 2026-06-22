<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Explain;

/**
 * Why the evaluator resolved a particular value (pinned wire contract — Block 19).
 *
 * This enum is a wire-contract surface: client SDKs, the preview endpoint, and the
 * future rule-builder UI all rely on these values. Never rename or remove a case
 * without a migration strategy.
 */
enum EvaluationReason: string
{
    case RuleMatch           = 'RULE_MATCH';
    case PercentageRollout   = 'PERCENTAGE_ROLLOUT';
    case TargetMatch         = 'TARGET_MATCH';
    case ScheduleWindow      = 'SCHEDULE_WINDOW';
    case ScheduleRamp        = 'SCHEDULE_RAMP';
    case VariantOverride     = 'VARIANT_OVERRIDE';
    case PrerequisiteFailed  = 'PREREQUISITE_FAILED';
    case AuthzDenied         = 'AUTHZ_DENIED';
    case Disabled            = 'DISABLED';
    case Archived            = 'ARCHIVED';
    case Default             = 'DEFAULT';
    case Override            = 'OVERRIDE';
    case Error               = 'ERROR';
}
