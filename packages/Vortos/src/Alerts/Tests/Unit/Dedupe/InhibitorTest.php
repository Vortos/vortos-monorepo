<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\InhibitionRule;
use Vortos\Alerts\Dedupe\Inhibitor;

final class InhibitorTest extends TestCase
{
    public function test_root_alert_suppresses_dependent(): void
    {
        $inhibitor = new Inhibitor();
        $rules = [new InhibitionRule('host-down', 'service-unreachable', 600)];
        $now = new DateTimeImmutable();

        $suppressed = $inhibitor->shouldSuppress($rules, 'service-unreachable', static fn (string $id): bool => $id === 'host-down', $now);

        self::assertTrue($suppressed);
    }

    public function test_not_suppressed_when_source_inactive(): void
    {
        $inhibitor = new Inhibitor();
        $rules = [new InhibitionRule('host-down', 'service-unreachable', 600)];
        $now = new DateTimeImmutable();

        $suppressed = $inhibitor->shouldSuppress($rules, 'service-unreachable', static fn (string $id): bool => false, $now);

        self::assertFalse($suppressed);
    }

    public function test_unrelated_rule_does_not_suppress(): void
    {
        $inhibitor = new Inhibitor();
        $rules = [new InhibitionRule('host-down', 'other-alert', 600)];
        $now = new DateTimeImmutable();

        $suppressed = $inhibitor->shouldSuppress($rules, 'service-unreachable', static fn (string $id): bool => true, $now);

        self::assertFalse($suppressed);
    }

    public function test_inhibition_rule_rejects_self_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InhibitionRule('same', 'same', 60);
    }
}
