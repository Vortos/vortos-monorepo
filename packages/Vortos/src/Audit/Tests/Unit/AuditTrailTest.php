<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Action\AuditActionProviderInterface;
use Vortos\Audit\Action\AuditActionRegistry;
use Vortos\Audit\Action\RegisteredAction;
use Vortos\Audit\AuditTrail;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Exception\UnknownAuditActionException;
use Vortos\Audit\Recorder\BufferingAuditRecorder;

final class AuditTrailTest extends TestCase
{
    public function test_strict_mode_rejects_undeclared_action(): void
    {
        $trail = new AuditTrail(new BufferingAuditRecorder(), $this->registry(), strict: true);

        $this->expectException(UnknownAuditActionException::class);
        $trail->record(Scope::Tenant, 'org-1', AuditActor::system(), 'totally.made.up');
    }

    public function test_non_strict_mode_records_undeclared_action_as_normal(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry(), strict: false);

        $trail->record(Scope::Tenant, 'org-1', AuditActor::system(), 'totally.made.up');

        self::assertSame(Sensitivity::Normal, $buffer->last()?->sensitivity);
    }

    public function test_resolves_declared_default_sensitivity(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry());

        $trail->record(Scope::Platform, null, AuditActor::system(), 'flag.published');

        self::assertSame(Sensitivity::High, $buffer->last()?->sensitivity);
    }

    public function test_caller_cannot_downgrade_below_declared_floor(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry());

        // flag.published is declared High; caller asks for Low — must stay High.
        $trail->record(Scope::Platform, null, AuditActor::system(), 'flag.published', sensitivity: Sensitivity::Low);

        self::assertSame(Sensitivity::High, $buffer->last()?->sensitivity);
    }

    public function test_caller_can_escalate_above_declared_default(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry());

        // member.invited is declared Normal; caller escalates to High.
        $trail->record(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', sensitivity: Sensitivity::High);

        self::assertSame(Sensitivity::High, $buffer->last()?->sensitivity);
    }

    public function test_forwards_explicit_occurred_at_to_the_event(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry());

        // The true business-event time (e.g. an async handler passing the domain event's own
        // timestamp) must be stamped on the row, not the moment record() happened to run.
        $eventTime = new \DateTimeImmutable('2026-07-16T12:29:57.123456+00:00');
        $trail->record(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', occurredAt: $eventTime);

        self::assertEquals($eventTime, $buffer->last()?->occurredAt);
    }

    public function test_defaults_occurred_at_to_now_for_inline_recorders(): void
    {
        $buffer = new BufferingAuditRecorder();
        $trail  = new AuditTrail($buffer, $this->registry());

        $before = new \DateTimeImmutable();
        $trail->record(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited');
        $after  = new \DateTimeImmutable();

        $stamped = $buffer->last()?->occurredAt;
        self::assertNotNull($stamped);
        self::assertGreaterThanOrEqual($before, $stamped);
        self::assertLessThanOrEqual($after, $stamped);
    }

    private function registry(): AuditActionRegistry
    {
        return new AuditActionRegistry([
            new class implements AuditActionProviderInterface {
                public function actions(): array
                {
                    return [
                        new RegisteredAction('member.invited', 'Member invited'),
                        new RegisteredAction('flag.published', 'Flag published', Sensitivity::High, Scope::Platform),
                    ];
                }
            },
        ]);
    }
}
