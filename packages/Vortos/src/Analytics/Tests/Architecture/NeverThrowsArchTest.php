<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Driver\Null\NullAnalytics;
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
use Vortos\Analytics\Runtime\BatchingAnalytics;
use Vortos\Analytics\Runtime\IdentityDedupeCache;
use Vortos\Analytics\Runtime\PrivacyFilteringAnalytics;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The headline contract (identical discipline to `ErrorSinkInterface`): every
 * public method on every `AnalyticsInterface` implementation in core MUST NOT
 * throw into the caller, even when the inner collaborator explodes. Behavioral, not
 * just a static scan — the whole composed decorator chain is exercised end-to-end
 * against a driver that throws from every method.
 */
final class NeverThrowsArchTest extends TestCase
{
    public function test_null_driver_never_throws(): void
    {
        $this->exerciseFullLifecycle(new NullAnalytics());
        $this->addToAssertionCount(1);
    }

    public function test_full_decorator_chain_never_throws_even_when_driver_explodes(): void
    {
        $throwingDriver = new class implements AnalyticsInterface {
            public function name(): string { return 'throwing'; }
            public function capture(AnalyticsEvent $event): void { throw new RuntimeException('boom'); }
            public function identify(IdentitySet $identity): void { throw new RuntimeException('boom'); }
            public function group(GroupAssociation $group): void { throw new RuntimeException('boom'); }
            public function flush(): void { throw new RuntimeException('boom'); }
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };

        $resolver = new class implements ConsentResolverInterface {
            public function resolve(DistinctId $distinctId): ConsentDecision
            {
                return ConsentDecision::Granted;
            }
        };

        $filter = new PrivacyFilter(
            new ConsentGate($resolver),
            new PropertyAllowlist(['plan']),
            new PropertyAllowlist(['plan']),
            new PiiRedactor('salt'),
        );

        $chain = new BatchingAnalytics(
            new PrivacyFilteringAnalytics($throwingDriver, $filter),
            new IdentityDedupeCache(),
            flushAt: 1, // force an immediate forward on the first capture()
        );

        $this->exerciseFullLifecycle($chain);
        $this->addToAssertionCount(1);
    }

    private function exerciseFullLifecycle(AnalyticsInterface $analytics): void
    {
        $analytics->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt', ['plan' => 'pro']));
        $analytics->identify(new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']));
        $analytics->group(new GroupAssociation(new DistinctId('user-1'), 'org', 'acme'));
        $analytics->flush();
        $analytics->capabilities();
    }
}
