<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Privacy\ConsentDecision;
use Vortos\Analytics\Privacy\ConsentGate;
use Vortos\Analytics\Privacy\ConsentResolverInterface;
use Vortos\Analytics\Privacy\PiiRedactor;
use Vortos\Analytics\Privacy\PrivacyFilter;
use Vortos\Analytics\Privacy\PropertyAllowlist;
use Vortos\Analytics\Privacy\ReservedProperties;

final class PrivacyFilterTest extends TestCase
{
    public function test_full_suppression_on_denied_consent_returns_null_for_event(): void
    {
        $filter = $this->filter(ConsentDecision::Denied, ['plan']);
        $this->assertNull($filter->apply($this->event(['plan' => 'pro'])));
    }

    public function test_full_suppression_on_unknown_consent_returns_null(): void
    {
        $filter = $this->filter(ConsentDecision::Unknown, ['plan']);
        $this->assertNull($filter->apply($this->event(['plan' => 'pro'])));
    }

    public function test_full_suppression_applies_to_identify_and_group_too(): void
    {
        $filter = $this->filter(ConsentDecision::Denied, ['plan']);

        $this->assertNull($filter->applyIdentity(new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro'])));
        $this->assertNull($filter->applyGroup(new GroupAssociation(new DistinctId('user-1'), 'org', 'acme', ['plan' => 'pro'])));
    }

    public function test_granted_consent_composes_allowlist_then_redactor_in_order(): void
    {
        $filter = $this->filter(ConsentDecision::Granted, ['plan', 'email']);

        $result = $filter->apply($this->event(['plan' => 'pro', 'email' => 'a@b.com', 'secret' => 'leak']));

        $this->assertNotNull($result);
        $this->assertSame('pro', $result->properties['plan']);
        $this->assertArrayNotHasKey('secret', $result->properties, 'allowlist must drop unknown keys');
        $this->assertStringStartsWith('sha256:', $result->properties['email'], 'redactor must still hash an allowlisted PII-shaped value');
    }

    public function test_granted_consent_event_preserves_name_and_distinct_id(): void
    {
        $filter = $this->filter(ConsentDecision::Granted, ['plan']);
        $result = $filter->apply($this->event(['plan' => 'pro']));

        $this->assertNotNull($result);
        $this->assertSame('signup', $result->name);
        $this->assertSame('user-1', $result->distinctId->value);
    }

    public function test_the_exposure_event_keeps_its_own_flag_and_variant(): void
    {
        // The regression this guards. With a normal allowlist (route, method, status, org_id)
        // the bridge's `flag` and `variant` were stripped here, and the PostHog mapper
        // faithfully emitted `$feature_flag: ''` for every exposure — a bridge that looked
        // wired, threw no error, and produced no usable data.
        $filter = $this->filter(ConsentDecision::Granted, ['route', 'method', 'status', 'org_id']);

        $result = $filter->apply(new AnalyticsEvent(new DistinctId('user-1'), 'feature_flag_exposure', [
            'flag' => 'checkout',
            'variant' => 'blue',
            'exposure_source' => 'server',
        ]));

        $this->assertSame('checkout', $result?->properties['flag']);
        $this->assertSame('blue', $result?->properties['variant']);
        $this->assertSame('server', $result?->properties['exposure_source']);
    }

    public function test_reserved_event_keys_are_scoped_to_the_event_that_owns_them(): void
    {
        // `flag` bypassing the allowlist on the exposure event must not open a hole for an
        // app-authored `flag` property on an unrelated event.
        $filter = $this->filter(ConsentDecision::Granted, ['plan']);

        $result = $filter->apply($this->event(['flag' => 'should-not-survive', 'plan' => 'pro']));

        $this->assertArrayNotHasKey('flag', $result?->properties ?? []);
        $this->assertSame('pro', $result?->properties['plan']);
    }

    public function test_flag_attribution_survives_the_allowlist_on_every_event(): void
    {
        $filter = $this->filter(ConsentDecision::Granted, ['plan']);

        $result = $filter->apply($this->event([
            ReservedProperties::ACTIVE_FLAGS => ['checkout' => 'blue'],
            'plan' => 'pro',
        ]));

        $this->assertSame(['checkout' => 'blue'], $result?->properties[ReservedProperties::ACTIVE_FLAGS]);
    }

    public function test_reserved_keys_do_not_bypass_the_consent_gate(): void
    {
        // Reserving a key exempts it from the allowlist only. Consent still governs whether
        // anything is emitted at all.
        $filter = $this->filter(ConsentDecision::Denied, ['plan']);

        $this->assertNull($filter->apply(new AnalyticsEvent(new DistinctId('user-1'), 'feature_flag_exposure', [
            'flag' => 'checkout',
        ])));
    }

    public function test_a_reserved_key_the_caller_never_sent_is_not_invented(): void
    {
        $filter = $this->filter(ConsentDecision::Granted, ['plan']);

        $result = $filter->apply($this->event(['plan' => 'pro']));

        $this->assertArrayNotHasKey(ReservedProperties::ACTIVE_FLAGS, $result?->properties ?? []);
    }

    public function test_identity_traits_keep_flag_attribution_despite_deny_all_traits(): void
    {
        // Trait allowlists default to deny-all, which is right for PII and would otherwise
        // make person-level flag cohorts impossible.
        $filter = $this->filter(ConsentDecision::Granted, []);

        $result = $filter->applyIdentity(new IdentitySet(new DistinctId('user-1'), [
            ReservedProperties::ACTIVE_FLAGS => ['checkout' => 'blue'],
            'email' => 'a@b.com',
        ]));

        $this->assertSame(['checkout' => 'blue'], $result?->traits[ReservedProperties::ACTIVE_FLAGS]);
        $this->assertArrayNotHasKey('email', $result?->traits ?? []);
    }

    private function filter(ConsentDecision $decision, array $allowedKeys): PrivacyFilter
    {
        $resolver = new class ($decision) implements ConsentResolverInterface {
            public function __construct(private ConsentDecision $decision) {}

            public function resolve(DistinctId $distinctId): ConsentDecision
            {
                return $this->decision;
            }
        };

        return new PrivacyFilter(
            new ConsentGate($resolver),
            new PropertyAllowlist($allowedKeys),
            new PropertyAllowlist($allowedKeys),
            new PiiRedactor('salt'),
        );
    }

    private function event(array $properties): AnalyticsEvent
    {
        return new AnalyticsEvent(new DistinctId('user-1'), 'signup', $properties);
    }
}
