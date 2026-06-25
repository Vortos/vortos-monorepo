<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
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
use Vortos\Analytics\Runtime\PrivacyFilteringAnalytics;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class PrivacyFilteringAnalyticsTest extends TestCase
{
    public function test_denied_consent_suppresses_capture_before_reaching_driver(): void
    {
        $inner = $this->spyDriver();
        $decorator = new PrivacyFilteringAnalytics($inner, $this->filter(ConsentDecision::Denied));

        $decorator->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));

        $this->assertSame([], $inner->captured);
    }

    public function test_granted_consent_forwards_filtered_event(): void
    {
        $inner = $this->spyDriver();
        $decorator = new PrivacyFilteringAnalytics($inner, $this->filter(ConsentDecision::Granted, ['plan']));

        $decorator->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt', ['plan' => 'pro', 'secret' => 'x']));

        $this->assertCount(1, $inner->captured);
        $this->assertArrayNotHasKey('secret', $inner->captured[0]->properties);
    }

    public function test_throwing_inner_driver_never_propagates(): void
    {
        $inner = $this->throwingDriver();
        $decorator = new PrivacyFilteringAnalytics($inner, $this->filter(ConsentDecision::Granted, ['plan']));

        $decorator->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));
        $decorator->identify(new IdentitySet(new DistinctId('user-1')));
        $decorator->group(new GroupAssociation(new DistinctId('user-1'), 'org', 'acme'));
        $decorator->flush();

        $this->addToAssertionCount(4);
    }

    public function test_name_and_capabilities_delegate_to_inner(): void
    {
        $inner = $this->spyDriver();
        $decorator = new PrivacyFilteringAnalytics($inner, $this->filter(ConsentDecision::Granted, []));

        $this->assertSame($inner->name(), $decorator->name());
        $this->assertSame($inner->capabilities()->toArray(), $decorator->capabilities()->toArray());
    }

    private function filter(ConsentDecision $decision, array $allowedKeys = []): PrivacyFilter
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

    private function spyDriver(): AnalyticsInterface
    {
        return new class implements AnalyticsInterface {
            /** @var list<AnalyticsEvent> */
            public array $captured = [];

            public function name(): string
            {
                return 'spy';
            }

            public function capture(AnalyticsEvent $event): void
            {
                $this->captured[] = $event;
            }

            public function identify(IdentitySet $identity): void {}

            public function group(GroupAssociation $group): void {}

            public function flush(): void {}

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }

    private function throwingDriver(): AnalyticsInterface
    {
        return new class implements AnalyticsInterface {
            public function name(): string
            {
                return 'throwing';
            }

            public function capture(AnalyticsEvent $event): void
            {
                throw new RuntimeException('boom');
            }

            public function identify(IdentitySet $identity): void
            {
                throw new RuntimeException('boom');
            }

            public function group(GroupAssociation $group): void
            {
                throw new RuntimeException('boom');
            }

            public function flush(): void
            {
                throw new RuntimeException('boom');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }
}
