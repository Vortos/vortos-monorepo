<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

/**
 * Translates a Vortos flag name into a key PostHog will accept.
 *
 * ## Why this exists
 *
 * PostHog validates flag keys against `letters, numbers, hyphens (-) and underscores (_)`
 * and rejects anything else with a 400. Vortos flag names are dotted by convention —
 * `payments.bank_transfer`, `support.impersonation.enabled`, `tournaments.check_in` — so
 * the two vocabularies are simply incompatible, and every dotted flag failed to mirror:
 *
 *     failed  payments.bank_transfer — HTTP 400
 *             {"code":"invalid_key","detail":"Only letters, numbers, hyphens (-) &
 *              underscores (_) are allowed.","attr":"key"}
 *
 * Only flags that happened to contain no dot synced at all, which made the mirror look
 * like it worked while silently omitting most of the product's real flags.
 *
 * ## Why it must be used in FOUR places, not one
 *
 * PostHog joins flag *definitions* to flag *usage* by string equality on the key, across
 * three separate conventions:
 *
 *   - the flag definition itself (`key`, via {@see FlagDefinitionMapper});
 *   - `$feature_flag` on a `$feature_flag_called` event;
 *   - `$feature/<key>` stamped on every other event;
 *   - `$active_feature_flags` on the person profile.
 *
 * Sanitising in one place and not the others is worse than not sanitising at all: the
 * definitions would appear under `payments_bank_transfer` while every exposure arrived
 * under `payments.bank_transfer`, so each flag would show as defined-but-never-called AND
 * called-but-undefined at the same time. Both halves would look broken, and neither error
 * would say why. So every producer of a PostHog-facing flag key routes through here.
 *
 * ## The trade this makes
 *
 * Two Vortos names that differ only in a separator — `a.b` and `a_b` — collapse to one
 * PostHog key. That is accepted rather than defended against: adding a disambiguating
 * suffix would make every key unreadable in the PostHog UI to protect against a collision
 * that a single human-curated flag namespace does not produce. The mirror is a reporting
 * surface, not the source of truth; Vortos remains the authority on what a flag is.
 */
final class PosthogFlagKey
{
    /** Everything PostHog permits in a flag key. */
    private const ALLOWED = '/[^A-Za-z0-9_-]+/';

    /** Used when a name sanitises down to nothing at all, so the payload stays valid. */
    private const FALLBACK = 'flag';

    /**
     * The PostHog-safe form of a Vortos flag name.
     *
     * Deterministic and idempotent: a name that is already valid comes back untouched, so
     * this can be applied on any path without checking whether it has been applied already.
     */
    public static function forPosthog(string $vortosName): string
    {
        $sanitised = preg_replace(self::ALLOWED, '_', $vortosName);

        if (!is_string($sanitised) || trim($sanitised, '_-') === '') {
            return self::FALLBACK;
        }

        return $sanitised;
    }
}
