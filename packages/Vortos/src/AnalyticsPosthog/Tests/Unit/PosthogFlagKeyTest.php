<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagKey;

/**
 * PostHog rejects any flag key outside `[A-Za-z0-9_-]`, and Vortos names flags with dots.
 * Before this translation existed every dotted flag failed to mirror with a 400, so a
 * project's real flags were absent while the handful without a dot synced and made the
 * mirror look healthy.
 */
final class PosthogFlagKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'dotted name, the case that failed' => ['payments.bank_transfer', 'payments_bank_transfer'];
        yield 'several dots'                      => ['support.impersonation.enabled', 'support_impersonation_enabled'];
        yield 'already valid, left alone'         => ['tournaments_check_in', 'tournaments_check_in'];
        yield 'hyphens are permitted'             => ['new-checkout', 'new-checkout'];
        yield 'plain name'                        => ['test', 'test'];
        yield 'spaces and slashes'                => ['billing/plan change', 'billing_plan_change'];
        yield 'a run collapses to one separator'  => ['a...b', 'a_b'];
    }

    /** @dataProvider names */
    public function test_translates_a_vortos_name_to_a_key_posthog_accepts(string $vortos, string $expected): void
    {
        self::assertSame($expected, PosthogFlagKey::forPosthog($vortos));
    }

    /** @dataProvider names */
    public function test_every_result_satisfies_posthogs_own_rule(string $vortos): void
    {
        // The rule from PostHog's 400: "Only letters, numbers, hyphens (-) & underscores (_)".
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', PosthogFlagKey::forPosthog($vortos));
    }

    public function test_is_idempotent_so_it_can_be_applied_on_any_path(): void
    {
        // Four separate call sites sanitise the same name — the definition, the exposure
        // event, the per-event stamp and the person profile. Applying it twice must not
        // produce a third spelling.
        foreach (['payments.bank_transfer', 'already_fine', 'a...b'] as $name) {
            $once = PosthogFlagKey::forPosthog($name);
            self::assertSame($once, PosthogFlagKey::forPosthog($once), $name);
        }
    }

    public function test_a_name_with_nothing_usable_still_yields_a_valid_key(): void
    {
        // A payload with an empty key is a 400 of its own; better a useless-but-valid key
        // than a failed sync nobody can explain.
        self::assertSame('flag', PosthogFlagKey::forPosthog('...'));
        self::assertSame('flag', PosthogFlagKey::forPosthog(''));
    }
}
