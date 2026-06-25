<?php

declare(strict_types=1);

namespace Vortos\Analytics\Testing;

use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The analytics-port TCK (§10.4), published from core for every driver — including
 * the split `posthog` driver, which extends this *unchanged* (the agnostic-seam
 * proof, §10.7). The defining contract: every method is best-effort and MUST NOT
 * throw into the caller, even when an underlying transport/collaborator explodes
 * (proven where the driver allows one to be injected, e.g. the posthog subclass
 * injecting a throwing transport).
 */
abstract class AnalyticsConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createAnalytics(): AnalyticsInterface;

    protected function createDriver(): AnalyticsInterface
    {
        return $this->createAnalytics();
    }

    final public function test_name_matches_registered_key(): void
    {
        self::assertSame($this->expectedKey(), $this->createAnalytics()->name());
    }

    final public function test_capture_never_throws(): void
    {
        $this->createAnalytics()->capture($this->sampleEvent());
        $this->addToAssertionCount(1);
    }

    final public function test_identify_never_throws(): void
    {
        $this->createAnalytics()->identify($this->sampleIdentity());
        $this->addToAssertionCount(1);
    }

    final public function test_group_never_throws(): void
    {
        $this->createAnalytics()->group($this->sampleGroup());
        $this->addToAssertionCount(1);
    }

    final public function test_flush_never_throws(): void
    {
        $this->createAnalytics()->flush();
        $this->addToAssertionCount(1);
    }

    final public function test_full_lifecycle_never_throws(): void
    {
        $analytics = $this->createAnalytics();
        $analytics->capture($this->sampleEvent());
        $analytics->identify($this->sampleIdentity());
        $analytics->group($this->sampleGroup());
        $analytics->flush();
        $this->addToAssertionCount(1);
    }

    final protected function sampleEvent(): AnalyticsEvent
    {
        return new AnalyticsEvent(new DistinctId('user-1'), 'signup_completed', ['plan' => 'pro']);
    }

    final protected function sampleIdentity(): IdentitySet
    {
        return new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);
    }

    final protected function sampleGroup(): GroupAssociation
    {
        return new GroupAssociation(new DistinctId('user-1'), 'org', 'acme', ['seats' => 10]);
    }
}
