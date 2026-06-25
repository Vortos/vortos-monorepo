<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\SyntheticJourney;

final class SyntheticJourneyTest extends TestCase
{
    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyntheticJourney('', [
            new JourneyStep('POST', '/login', 200),
            new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
        ]);
    }

    public function testRejectsSingleStepJourney(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyntheticJourney('bare-ping', [
            new JourneyStep('GET', '/', 200, bodyContains: 'ok'),
        ]);
    }

    public function testRejectsZeroStepJourney(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyntheticJourney('empty', []);
    }

    public function testRejectsJourneyWithNoBodyAssertion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyntheticJourney('status-only', [
            new JourneyStep('POST', '/login', 200),
            new JourneyStep('GET', '/me', 200),
        ]);
    }

    public function testAcceptsMultiStepJourneyWithBodyAssertion(): void
    {
        $journey = new SyntheticJourney('login-fetch', [
            new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
            new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
        ]);

        self::assertSame('login-fetch', $journey->name);
        self::assertCount(2, $journey->steps);
    }
}
