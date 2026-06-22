<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Webhook\WebhookSubscription;

final class WebhookSubscriptionTest extends TestCase
{
    public function test_matches_wildcard_event(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['*'],
        );

        $this->assertTrue($sub->matchesEvent('flag.enabled', null, null));
        $this->assertTrue($sub->matchesEvent('flag.disabled', null, null));
    }

    public function test_matches_specific_event_type(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['flag.enabled', 'flag.disabled'],
        );

        $this->assertTrue($sub->matchesEvent('flag.enabled', null, null));
        $this->assertFalse($sub->matchesEvent('flag.created', null, null));
    }

    public function test_empty_event_types_matches_all(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: [],
        );

        $this->assertTrue($sub->matchesEvent('flag.anything', null, null));
    }

    public function test_inactive_subscription_never_matches(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['*'], active: false,
        );

        $this->assertFalse($sub->matchesEvent('flag.enabled', null, null));
    }

    public function test_project_scoping(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['*'], projectId: 'project-a',
        );

        $this->assertTrue($sub->matchesEvent('flag.enabled', 'project-a', null));
        $this->assertFalse($sub->matchesEvent('flag.enabled', 'project-b', null));
        $this->assertTrue($sub->matchesEvent('flag.enabled', null, null)); // null = no project filter from event
    }

    public function test_environment_scoping(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['*'], environment: 'production',
        );

        $this->assertTrue($sub->matchesEvent('flag.enabled', null, 'production'));
        $this->assertFalse($sub->matchesEvent('flag.enabled', null, 'staging'));
    }

    public function test_combined_project_and_env_scoping(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'h',
            eventTypes: ['flag.enabled'], projectId: 'p1', environment: 'production',
        );

        $this->assertTrue($sub->matchesEvent('flag.enabled', 'p1', 'production'));
        $this->assertFalse($sub->matchesEvent('flag.enabled', 'p1', 'staging'));
        $this->assertFalse($sub->matchesEvent('flag.enabled', 'p2', 'production'));
        $this->assertFalse($sub->matchesEvent('flag.disabled', 'p1', 'production'));
    }

    public function test_to_array_does_not_include_secret(): void
    {
        $sub = new WebhookSubscription(
            id: 's1', url: 'https://example.com/hook', secretHash: 'secret-hash',
            eventTypes: ['*'],
        );

        $arr = $sub->toArray();

        $this->assertArrayNotHasKey('secretHash', $arr);
        $this->assertArrayNotHasKey('secret_hash', $arr);
        $this->assertArrayNotHasKey('secret', $arr);
    }
}
