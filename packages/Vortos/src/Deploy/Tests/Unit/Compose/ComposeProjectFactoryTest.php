<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Compose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Target\ActiveColor;

final class ComposeProjectFactoryTest extends TestCase
{
    private static function digestPinnedImage(): ImageReference
    {
        return new ImageReference('myrepo/app', 'latest', 'sha256:' . str_repeat('ab', 32));
    }

    private static function factory(int $containerPort = 8080): ComposeProjectFactory
    {
        return new ComposeProjectFactory(new RuntimeServiceSpec(
            command: ['frankenphp', 'run'],
            containerPort: $containerPort,
        ));
    }

    public function test_creates_blue_compose_with_spec_command(): void
    {
        $compose = self::factory()->create(ActiveColor::Blue, self::digestPinnedImage());

        $this->assertSame('vortos-app-blue', $compose->projectName);
        $this->assertSame(ActiveColor::Blue, $compose->color);
        $this->assertSame(['frankenphp', 'run'], $compose->toArray()['services']['app-blue']['command']);
    }

    public function test_creates_green_compose(): void
    {
        $compose = self::factory()->create(ActiveColor::Green, self::digestPinnedImage());

        $this->assertSame('vortos-app-green', $compose->projectName);
        $this->assertSame(['8080'], $compose->toArray()['services']['app-green']['expose']);
    }

    public function test_endpoint_port_is_the_internal_container_port_not_a_host_port(): void
    {
        // B16 regression: endpointFor previously returned the host-published 8081/8082, which the
        // container never listens on internally, so the Caddy dial + readiness gate both missed it.
        $endpoint = self::factory(8080)->endpointFor(ActiveColor::Blue);

        $this->assertSame('app-blue', $endpoint->host);
        $this->assertSame(8080, $endpoint->port);
    }

    public function test_both_colors_share_the_same_internal_port(): void
    {
        $factory = self::factory(9000);

        // Separate containers with distinct network aliases → they can (and do) share one internal port.
        $this->assertSame(9000, $factory->endpointFor(ActiveColor::Blue)->port);
        $this->assertSame(9000, $factory->endpointFor(ActiveColor::Green)->port);
        $this->assertSame('app-green', $factory->endpointFor(ActiveColor::Green)->host);
    }
}
