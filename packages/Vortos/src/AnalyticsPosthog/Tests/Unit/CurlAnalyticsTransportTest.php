<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\AnalyticsPosthog\CurlAnalyticsTransport;

final class CurlAnalyticsTransportTest extends TestCase
{
    public function test_rejects_non_https_scheme(): void
    {
        $transport = new CurlAnalyticsTransport();
        $this->assertFalse($transport->send('http://example.com/batch', '{}', []));
    }

    public function test_rejects_loopback_destination(): void
    {
        $transport = new CurlAnalyticsTransport();
        $this->assertFalse($transport->send('https://127.0.0.1/batch', '{}', []));
    }

    public function test_rejects_link_local_metadata_destination(): void
    {
        $transport = new CurlAnalyticsTransport();
        $this->assertFalse($transport->send('https://169.254.169.254/latest/meta-data', '{}', []));
    }

    public function test_rejects_malformed_url(): void
    {
        $transport = new CurlAnalyticsTransport();
        $this->assertFalse($transport->send('not a url', '{}', []));
    }

    public function test_never_throws_on_unreachable_host(): void
    {
        // A bounded, very short timeout against a non-routable test address —
        // proves send() returns false rather than throwing, never hanging the suite.
        $transport = new CurlAnalyticsTransport(connectTimeoutSeconds: 1, totalTimeoutSeconds: 1);
        $result = $transport->send('https://198.51.100.1/batch', '{}', []);

        $this->assertFalse($result);
    }

    public function test_never_throws_on_any_input(): void
    {
        $transport = new CurlAnalyticsTransport();
        $transport->send('', '', []);
        $transport->send('https://[::1]/batch', '{}', []);
        $this->addToAssertionCount(1);
    }
}
