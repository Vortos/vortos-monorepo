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
