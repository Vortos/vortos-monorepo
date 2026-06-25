<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests;

use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Testing\AnalyticsConformanceTestCase;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;
use Vortos\AnalyticsPosthog\PosthogAnalytics;
use Vortos\AnalyticsPosthog\PosthogEventMapper;

/**
 * Extends the **core** TCK unchanged — the headline "agnostic seam" proof (§10.7):
 * a real backend driver, shipped from a separate package, passes the exact same
 * conformance suite as `NullAnalyticsConformanceTest`. A throwing transport proves
 * the never-throws contract holds even when the network call explodes.
 */
final class PosthogAnalyticsConformanceTest extends AnalyticsConformanceTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['POSTHOG_PROJECT_API_KEY']);
    }

    protected function createAnalytics(): AnalyticsInterface
    {
        $_ENV['POSTHOG_PROJECT_API_KEY'] = 'phc_test_key';

        return new PosthogAnalytics($this->throwingTransport(), new PosthogEventMapper());
    }

    protected function expectedKey(): string
    {
        return 'posthog';
    }

    private function throwingTransport(): AnalyticsTransportInterface
    {
        return new class implements AnalyticsTransportInterface {
            public function send(string $url, string $jsonBody, array $headers): bool
            {
                throw new \RuntimeException('transport exploded');
            }
        };
    }
}
