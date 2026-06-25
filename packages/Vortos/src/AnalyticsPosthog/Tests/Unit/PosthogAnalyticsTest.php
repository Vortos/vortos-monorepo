<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;
use Vortos\AnalyticsPosthog\PosthogAnalytics;
use Vortos\AnalyticsPosthog\PosthogEventMapper;

final class PosthogAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['POSTHOG_PROJECT_API_KEY'], $_ENV['POSTHOG_HOST']);
    }

    public function test_capture_buffers_without_sending(): void
    {
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));

        $this->assertSame([], $transport->calls);
        $this->assertSame(1, $driver->bufferedCount());
    }

    public function test_flush_without_api_key_drops_silently_never_sends(): void
    {
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));
        $driver->flush();

        $this->assertSame([], $transport->calls, 'must never send without a configured API key');
    }

    public function test_flush_sends_one_batch_for_multiple_buffered_calls(): void
    {
        $_ENV['POSTHOG_PROJECT_API_KEY'] = 'phc_test';
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt-a'));
        $driver->identify(new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']));
        $driver->group(new GroupAssociation(new DistinctId('user-1'), 'org', 'acme'));
        $driver->flush();

        $this->assertCount(1, $transport->calls, 'exactly one /batch POST for the whole flush');
        $body = json_decode($transport->calls[0]['body'], true);
        $this->assertSame('phc_test', $body['api_key']);
        $this->assertCount(3, $body['batch']);
        $this->assertSame(0, $driver->bufferedCount(), 'buffer must be cleared after flush');
    }

    public function test_flush_uses_default_host_when_not_configured(): void
    {
        $_ENV['POSTHOG_PROJECT_API_KEY'] = 'phc_test';
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));
        $driver->flush();

        $this->assertSame('https://us.i.posthog.com/batch', $transport->calls[0]['url']);
    }

    public function test_flush_uses_configured_host(): void
    {
        $_ENV['POSTHOG_PROJECT_API_KEY'] = 'phc_test';
        $_ENV['POSTHOG_HOST'] = 'https://eu.i.posthog.com/';
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->capture(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));
        $driver->flush();

        $this->assertSame('https://eu.i.posthog.com/batch', $transport->calls[0]['url']);
    }

    public function test_empty_flush_never_calls_transport(): void
    {
        $_ENV['POSTHOG_PROJECT_API_KEY'] = 'phc_test';
        $transport = $this->spyTransport();
        $driver = new PosthogAnalytics($transport, new PosthogEventMapper());

        $driver->flush();

        $this->assertSame([], $transport->calls);
    }

    public function test_capabilities_are_honest(): void
    {
        $driver = new PosthogAnalytics($this->spyTransport(), new PosthogEventMapper());
        $caps = $driver->capabilities();

        $this->assertTrue($caps->supports(\Vortos\Analytics\Capability\AnalyticsCapability::Batching));
        $this->assertTrue($caps->supports(\Vortos\Analytics\Capability\AnalyticsCapability::GroupAnalytics));
        $this->assertTrue($caps->supports(\Vortos\Analytics\Capability\AnalyticsCapability::ServerSide));
        $this->assertTrue($caps->supports(\Vortos\Analytics\Capability\AnalyticsCapability::OffHost));
        $this->assertFalse($caps->supports(\Vortos\Analytics\Capability\AnalyticsCapability::IdentityMerge));
    }

    private function spyTransport(): object
    {
        return new class implements AnalyticsTransportInterface {
            /** @var list<array{url:string,body:string,headers:array<string,string>}> */
            public array $calls = [];

            public function send(string $url, string $jsonBody, array $headers): bool
            {
                $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'headers' => $headers];

                return true;
            }
        };
    }
}
