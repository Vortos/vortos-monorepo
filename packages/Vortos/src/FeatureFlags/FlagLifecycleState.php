<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * The lifecycle stage of a feature flag (Block 12).
 *
 * Flags follow a one-way progression: Draft → Active → Archived.
 * Transitions are mutations on the Flag aggregate, recorded as domain events.
 *
 * | State    | Evaluator behaviour                             |
 * |----------|-------------------------------------------------|
 * | Draft    | Always returns the flag's default value (off).  |
 * |          | Draft flags are invisible to the SDK endpoint.  |
 * | Active   | Normal evaluation — rules, variants, schedule.  |
 * | Archived | Treated the same as Draft by the evaluator;     |
 * |          | the flag row is kept for audit history.         |
 *
 * The `lifecycle` field lives on the flag definition (not per-env state), so
 * it applies uniformly across all environments. A flag must be explicitly
 * promoted to `Active` before it can affect end users.
 */
enum FlagLifecycleState: string
{
    case Draft    = 'draft';
    case Active   = 'active';
    case Archived = 'archived';

    public function isLive(): bool
    {
        return $this === self::Active;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft    => $next === self::Active || $next === self::Archived,
            self::Active   => $next === self::Archived,
            self::Archived => false,
        };
    }
}
