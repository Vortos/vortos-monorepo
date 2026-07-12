<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\FailureMode;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Ingestion\AsyncAuditRecorder;
use Vortos\Audit\Ingestion\AuditEventRecorded;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Contract\EventBusInterface;

final class AsyncAuditRecorderTest extends TestCase
{
    private function event(): AuditEvent
    {
        return AuditEvent::create(Scope::Platform, null, AuditActor::system(), 'flag.published');
    }

    public function test_dispatches_audit_event_recorded_envelope(): void
    {
        $bus = new class implements EventBusInterface {
            public ?EventEnvelope $last = null;
            public function dispatch(EventEnvelope $envelope): void { $this->last = $envelope; }
            public function dispatchBatch(EventEnvelope ...$envelopes): void { $this->last = $envelopes[array_key_last($envelopes)] ?? null; }
        };

        (new AsyncAuditRecorder($bus))->record($this->event());

        self::assertInstanceOf(AuditEventRecorded::class, $bus->last?->payload);
        self::assertSame('AuditEvent', $bus->last->aggregateType);
        self::assertSame('flag.published', \Vortos\Audit\Event\AuditEvent::fromArray($bus->last->payload->event)->action);
    }

    public function test_envelope_is_keyed_by_chain_for_partition_ordering(): void
    {
        $bus = new class implements EventBusInterface {
            public ?EventEnvelope $last = null;
            public function dispatch(EventEnvelope $envelope): void { $this->last = $envelope; }
            public function dispatchBatch(EventEnvelope ...$envelopes): void { $this->last = $envelopes[array_key_last($envelopes)] ?? null; }
        };

        // Platform event → 'platform' chain key.
        (new AsyncAuditRecorder($bus))->record($this->event());
        self::assertSame('platform', $bus->last?->aggregateId, 'platform events co-partition on the platform chain');

        // Tenant event → 'tenant:{id}' chain key, identical for every event of that tenant.
        $tenantEvent = AuditEvent::create(Scope::Tenant, 'org-42', AuditActor::system(), 'member.invited');
        (new AsyncAuditRecorder($bus))->record($tenantEvent);
        self::assertSame('tenant:org-42', $bus->last?->aggregateId, 'tenant events co-partition on their own chain');
    }

    public function test_block_mode_rethrows_on_dispatch_failure(): void
    {
        $bus = $this->throwingBus();

        $this->expectException(\RuntimeException::class);
        (new AsyncAuditRecorder($bus, FailureMode::Block))->record($this->event());
    }

    public function test_drop_mode_swallows_dispatch_failure(): void
    {
        $bus = $this->throwingBus();

        (new AsyncAuditRecorder($bus, FailureMode::Drop))->record($this->event());
        $this->addToAssertionCount(1); // no exception == pass
    }

    private function throwingBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            public function dispatch(EventEnvelope $envelope): void { throw new \RuntimeException('broker down'); }
            public function dispatchBatch(EventEnvelope ...$envelopes): void { throw new \RuntimeException('broker down'); }
        };
    }
}
