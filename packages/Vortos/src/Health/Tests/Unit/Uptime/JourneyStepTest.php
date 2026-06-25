<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\JourneyStep;

final class JourneyStepTest extends TestCase
{
    public function testAssertsBodyInvariantTrueWhenBodyContainsSet(): void
    {
        $step = new JourneyStep('GET', '/me', 200, bodyContains: '"email"');

        self::assertTrue($step->assertsBodyInvariant());
    }

    public function testAssertsBodyInvariantFalseByDefault(): void
    {
        $step = new JourneyStep('POST', '/login', 200);

        self::assertFalse($step->assertsBodyInvariant());
    }

    public function testRejectsUnsupportedMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('OPTIONS', '/me', 200);
    }

    public function testRejectsPathNotStartingWithSlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('GET', 'me', 200);
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('GET', '', 200);
    }

    public function testRejectsOutOfRangeStatusBelow100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('GET', '/me', 99);
    }

    public function testRejectsOutOfRangeStatusAbove599(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('GET', '/me', 600);
    }

    public function testAllowsBoundaryStatus100And599(): void
    {
        self::assertSame(100, (new JourneyStep('GET', '/me', 100))->expectStatus);
        self::assertSame(599, (new JourneyStep('GET', '/me', 599))->expectStatus);
    }

    public function testRejectsEmptyBodyContains(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('GET', '/me', 200, bodyContains: '');
    }

    public function testRejectsExtractAsWithoutJsonPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JourneyStep('POST', '/login', 200, extractAs: 'token');
    }

    public function testAllowsExtractAsWithJsonPath(): void
    {
        $step = new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token');

        self::assertSame('token', $step->extractAs);
        self::assertSame('data.token', $step->extractJsonPath);
    }
}
