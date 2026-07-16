<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\ActorType;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Event\AuditSource;
use Vortos\Audit\Event\AuditTarget;

final class AuditEventTest extends TestCase
{
    public function test_tenant_scope_requires_tenant_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditEvent::create(
            scope: Scope::Tenant,
            tenantId: null,
            actor: AuditActor::system(),
            action: 'member.invited',
        );
    }

    public function test_platform_scope_forbids_tenant_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditEvent::create(
            scope: Scope::Platform,
            tenantId: 'org-1',
            actor: AuditActor::system(),
            action: 'org.plan.changed',
        );
    }

    public function test_chain_key_partitions_platform_and_tenant(): void
    {
        $platform = AuditEvent::create(Scope::Platform, null, AuditActor::system(), 'flag.published');
        $tenant   = AuditEvent::create(Scope::Tenant, 'org-42', AuditActor::system(), 'member.invited');

        self::assertSame('platform', $platform->chainKey());
        self::assertSame('tenant:org-42', $tenant->chainKey());
    }

    public function test_round_trips_through_array_including_impersonation_chain(): void
    {
        $operator = AuditActor::user('op-1', 'ops@sqoura.com', ['ROLE_SUPER_ADMIN']);
        $actor    = AuditActor::user('u-9', 'coach@club.com', ['ROLE_ORG_ADMIN'])->withOnBehalfOf($operator);

        $event = AuditEvent::create(
            scope: Scope::Tenant,
            tenantId: 'org-42',
            actor: $actor,
            action: 'member.role.granted',
            target: new AuditTarget('membership', 'm-7', 'Jane Coach'),
            sensitivity: Sensitivity::High,
            outcome: Outcome::Allowed,
            source: new AuditSource('203.0.113.9', 'curl/8', 'sess-1', 'req-1', 'dev-1'),
            context: ['role' => 'ROLE_ORG_ADMIN'],
        );

        $restored = AuditEvent::fromArray($event->toArray());

        self::assertSame($event->id, $restored->id);
        self::assertSame('member.role.granted', $restored->action);
        self::assertSame(Sensitivity::High, $restored->sensitivity);
        self::assertSame(ActorType::User, $restored->actor->type);
        self::assertTrue($restored->actor->isImpersonated());
        self::assertSame('op-1', $restored->actor->onBehalfOf?->id);
        self::assertSame('Jane Coach', $restored->target?->label);
        self::assertSame('203.0.113.9', $restored->source->ip);
        self::assertSame(['role' => 'ROLE_ORG_ADMIN'], $restored->context);
        self::assertEquals($event->occurredAt, $restored->occurredAt);
    }

    public function test_explicit_occurred_at_survives_wire_round_trip_at_microsecond_precision(): void
    {
        // The async ingestion path transports AuditEvent::toArray() over the bus and rebuilds it
        // with fromArray(). FB-5 relies on the true event-time surviving that hop exactly, so a
        // consumer draining a backlog stamps reality, not ingest-time. Microseconds must not be lost.
        $eventTime = new \DateTimeImmutable('2026-07-16T12:29:57.123456+00:00');

        $event = AuditEvent::create(
            scope: Scope::Tenant,
            tenantId: 'org-42',
            actor: AuditActor::system(),
            action: 'registration.submitted',
            occurredAt: $eventTime,
        );

        $restored = AuditEvent::fromArray($event->toArray());

        self::assertEquals($eventTime, $restored->occurredAt);
        self::assertSame('123456', $restored->occurredAt->format('u'));
    }
}
