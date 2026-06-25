<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Compose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;

final class ColorEndpointTest extends TestCase
{
    public function test_valid_endpoint(): void
    {
        $endpoint = new ColorEndpoint('app-green', 8082);

        $this->assertSame('app-green', $endpoint->host);
        $this->assertSame(8082, $endpoint->port);
    }

    public function test_to_url(): void
    {
        $endpoint = new ColorEndpoint('app-blue', 8081);

        $this->assertSame('http://app-blue:8081/health/ready', $endpoint->toUrl('/health/ready'));
        $this->assertSame('http://app-blue:8081/', $endpoint->toUrl());
    }

    public function test_rejects_empty_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorEndpoint('', 8080);
    }

    public function test_rejects_invalid_port(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorEndpoint('host', 0);
    }
}
