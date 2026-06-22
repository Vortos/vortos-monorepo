<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * The operational kind of a flag — drives policy, not value (orthogonal to
 * {@see FlagValueType}).
 *
 * - Release    → temporary rollout of new functionality.
 * - Experiment → A/B test; exposure tracking expected.
 * - Ops        → long-lived operational / kill-switch toggle.
 * - Permission → entitlement gate; MUST fail closed and MUST NOT target on
 *                client-controlled (untrusted) context.
 */
enum FlagKind: string
{
    case Release    = 'release';
    case Experiment = 'experiment';
    case Ops        = 'ops';
    case Permission = 'permission';

    /** Kinds whose targeting must never read attacker-controlled context. */
    public function requiresTrustedTargeting(): bool
    {
        return $this === self::Permission;
    }
}
