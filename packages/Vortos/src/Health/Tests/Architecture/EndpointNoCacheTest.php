<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Http\HealthController;

final class EndpointNoCacheTest extends TestCase
{
    public function testControllerClassSetsNoCacheHeaders(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(HealthController::class))->getFileName(),
        );

        self::assertStringContainsString("'Cache-Control', 'no-store", $source);
        self::assertStringContainsString("'Pragma', 'no-cache'", $source);
    }

    public function testControllerNeverEchoesRequestInput(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(HealthController::class))->getFileName(),
        );

        self::assertStringNotContainsString('getQueryString', $source);
        self::assertStringNotContainsString('getContent()', $source);
        self::assertStringNotContainsString('$request->get(', $source);
    }
}
