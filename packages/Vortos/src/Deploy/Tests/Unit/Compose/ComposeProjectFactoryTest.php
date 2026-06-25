<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Compose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\ActiveColor;

final class ComposeProjectFactoryTest extends TestCase
{
    private static function digestPinnedImage(): ImageReference
    {
        return new ImageReference('myrepo/app', 'latest', 'sha256:' . str_repeat('ab', 32));
    }

    public function test_creates_blue_compose(): void
    {
        $factory = new ComposeProjectFactory();
        $compose = $factory->create(ActiveColor::Blue, self::digestPinnedImage());

        $this->assertSame('vortos-app-blue', $compose->projectName);
        $this->assertSame(ActiveColor::Blue, $compose->color);
        $this->assertSame(8081, $compose->appPort);
    }

    public function test_creates_green_compose(): void
    {
        $factory = new ComposeProjectFactory();
        $compose = $factory->create(ActiveColor::Green, self::digestPinnedImage());

        $this->assertSame('vortos-app-green', $compose->projectName);
        $this->assertSame(8082, $compose->appPort);
    }

    public function test_endpoint_for_blue(): void
    {
        $factory = new ComposeProjectFactory();
        $endpoint = $factory->endpointFor(ActiveColor::Blue);

        $this->assertSame('app-blue', $endpoint->host);
        $this->assertSame(8081, $endpoint->port);
    }

    public function test_endpoint_for_green(): void
    {
        $factory = new ComposeProjectFactory();
        $endpoint = $factory->endpointFor(ActiveColor::Green);

        $this->assertSame('app-green', $endpoint->host);
        $this->assertSame(8082, $endpoint->port);
    }
}
