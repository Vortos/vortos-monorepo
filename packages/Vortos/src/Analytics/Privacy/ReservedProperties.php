<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

/**
 * The framework's own control properties, which must survive an app's event allowlist.
 *
 * ## Why this exists
 *
 * {@see PropertyAllowlist} is deny-by-default and drops every key an app has not
 * explicitly allowed. That is exactly right for app-authored properties, which are the
 * surface PII leaks through. It is exactly wrong for properties the *framework* generates
 * to describe the event itself: with a typical allowlist, the flag-exposure bridge's
 * `flag` and `variant` were silently stripped, and the PostHog mapper faithfully emitted
 * `$feature_flag: ''` on every exposure — a bridge that looked wired, ran without error,
 * and produced no usable data. Relying on each app remembering to allowlist the
 * framework's own keys is not a contract; it is a trap.
 *
 * ## Scope discipline
 *
 * A reserved key is not a general escape hatch:
 *
 *  - {@see GLOBAL_KEYS} are reserved on every event. Deliberately tiny, and each is
 *    prefixed `_vortos_` so it can never collide with an app property name.
 *  - {@see EVENT_KEYS} reserve keys **only on the framework event that owns them**, so
 *    `flag` bypassing the allowlist on `feature_flag_exposure` does not also let an
 *    app-authored `flag` property through on an unrelated event.
 *
 * Reserved keys bypass the *allowlist* only. The consent gate and the PII redactor still
 * apply to them, unchanged — reserving a key never means trusting it blindly.
 */
final readonly class ReservedProperties
{
    /** Flag attribution stamped on every event: flag name => evaluated variant. */
    public const ACTIVE_FLAGS = '_vortos_active_flags';

    /** Reserved on every event. Keep this list minimal. */
    public const GLOBAL_KEYS = [self::ACTIVE_FLAGS];

    /**
     * Reserved per framework-owned event name.
     *
     * `feature_flag_exposure` is emitted by `Analytics\Bridge\AnalyticsExposureObserver`;
     * its three properties are wholly framework-authored and contain no user data — the
     * flag name and its evaluated result, plus which side produced the exposure.
     *
     * @var array<string,list<string>>
     */
    public const EVENT_KEYS = [
        'feature_flag_exposure' => ['flag', 'variant', 'exposure_source'],
    ];

    /**
     * The reserved keys in force for `$eventName` — the global set plus anything that
     * event owns.
     *
     * @return list<string>
     */
    public static function forEvent(string $eventName): array
    {
        return array_values(array_unique(
            array_merge(self::GLOBAL_KEYS, self::EVENT_KEYS[$eventName] ?? []),
        ));
    }

    /**
     * Reserved keys that apply outside any event (identify/group traits).
     *
     * @return list<string>
     */
    public static function global(): array
    {
        return self::GLOBAL_KEYS;
    }
}
