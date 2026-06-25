<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Escalation;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\MaintenanceSilence;

final class MaintenanceSilenceTest extends TestCase
{
    public function test_rejects_open_ended_silence_exceeding_max_duration(): void
    {
        $now = new DateTimeImmutable();

        $this->expectException(InvalidArgumentException::class);
        new MaintenanceSilence('s1', '*', $now, $now->modify('+31 days'), 'ops', 'too long');
    }

    public function test_rejects_expiry_before_start(): void
    {
        $now = new DateTimeImmutable();

        $this->expectException(InvalidArgumentException::class);
        new MaintenanceSilence('s1', '*', $now, $now->modify('-1 minute'), 'ops', 'bad');
    }

    public function test_wildcard_covers_every_rule(): void
    {
        $now = new DateTimeImmutable();
        $silence = new MaintenanceSilence('s1', '*', $now, $now->modify('+1 hour'), 'ops', 'maintenance');

        self::assertTrue($silence->coversRule('anything'));
    }

    public function test_specific_rule_does_not_cover_other_rules(): void
    {
        $now = new DateTimeImmutable();
        $silence = new MaintenanceSilence('s1', 'rule-a', $now, $now->modify('+1 hour'), 'ops', 'maintenance');

        self::assertTrue($silence->coversRule('rule-a'));
        self::assertFalse($silence->coversRule('rule-b'));
    }

    public function test_is_active_only_within_window(): void
    {
        $now = new DateTimeImmutable();
        $silence = new MaintenanceSilence('s1', '*', $now, $now->modify('+10 minutes'), 'ops', 'maintenance');

        self::assertTrue($silence->isActiveAt($now->modify('+5 minutes')));
        self::assertFalse($silence->isActiveAt($now->modify('-1 second')));
        self::assertFalse($silence->isActiveAt($now->modify('+10 minutes'))); // expiry boundary is exclusive
        self::assertFalse($silence->isActiveAt($now->modify('+11 minutes')));
    }
}
