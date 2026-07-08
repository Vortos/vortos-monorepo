<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Collector;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorBufferPolicy;

final class CollectorBufferPolicyTest extends TestCase
{
    public function test_defaults_are_valid(): void
    {
        $policy = new CollectorBufferPolicy();

        self::assertSame(256, $policy->memoryLimitMib);
        self::assertNotEmpty($policy->cardinalityDenyList);
    }

    public function test_rejects_empty_storage_dir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(storageDir: '');
    }

    public function test_rejects_low_memory_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(memoryLimitMib: 16);
    }

    public function test_rejects_spike_ge_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(memoryLimitMib: 100, memorySpikeMib: 100);
    }

    public function test_rejects_zero_retry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(retryMaxSeconds: 0);
    }

    public function test_rejects_empty_deny_list_entry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(cardinalityDenyList: ['ok', '']);
    }

    public function test_host_container_metrics_off_by_default(): void
    {
        $policy = new CollectorBufferPolicy();

        self::assertFalse($policy->hostMetrics);
        self::assertFalse($policy->containerMetrics);
        self::assertSame('tcp://docker-socket-proxy:2375', $policy->containerStatsEndpoint);
        self::assertSame('1.44', $policy->dockerApiVersion);
    }

    public function test_rejects_empty_container_stats_endpoint_when_enabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(containerMetrics: true, containerStatsEndpoint: '');
    }

    public function test_rejects_empty_docker_api_version_when_enabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorBufferPolicy(containerMetrics: true, dockerApiVersion: '');
    }

    public function test_empty_container_endpoint_allowed_when_disabled(): void
    {
        // Only validated when the receiver is actually enabled.
        $policy = new CollectorBufferPolicy(containerMetrics: false, containerStatsEndpoint: '');

        self::assertFalse($policy->containerMetrics);
    }
}
