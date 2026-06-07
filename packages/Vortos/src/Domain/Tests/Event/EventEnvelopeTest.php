<?php

declare(strict_types=1);

namespace Vortos\Domain\Tests\Event;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;

final readonly class EnvelopeTestPayload
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}
}

final class EventEnvelopeTest extends TestCase
{
    private function makeEnvelope(?Metadata $metadata = null): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          '0192abcd-ef01-7000-8000-000000000001',
            aggregateId:      'user-1',
            aggregateType:    'App\\User\\Domain\\User',
            aggregateVersion: 1,
            payloadType:      EnvelopeTestPayload::class,
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable('2026-05-21T10:00:00+00:00'),
            payload:          new EnvelopeTestPayload('a@b.com', 'Sacin'),
            metadata:         $metadata ?? Metadata::empty(),
        );
    }

    public function test_exposes_all_fields(): void
    {
        $envelope = $this->makeEnvelope();

        $this->assertSame('0192abcd-ef01-7000-8000-000000000001', $envelope->eventId);
        $this->assertSame('user-1', $envelope->aggregateId);
        $this->assertSame('App\\User\\Domain\\User', $envelope->aggregateType);
        $this->assertSame(1, $envelope->aggregateVersion);
        $this->assertSame(EnvelopeTestPayload::class, $envelope->payloadType);
        $this->assertSame(1, $envelope->schemaVersion);
        $this->assertEquals(new \DateTimeImmutable('2026-05-21T10:00:00+00:00'), $envelope->occurredAt);
        $this->assertInstanceOf(EnvelopeTestPayload::class, $envelope->payload);
        $this->assertInstanceOf(Metadata::class, $envelope->metadata);
    }

    public function test_payload_keeps_its_type(): void
    {
        $envelope = $this->makeEnvelope();

        $this->assertInstanceOf(EnvelopeTestPayload::class, $envelope->payload);
        $this->assertSame('a@b.com', $envelope->payload->email);
        $this->assertSame('Sacin', $envelope->payload->name);
    }

    public function test_with_metadata_returns_new_instance(): void
    {
        $original = $this->makeEnvelope();
        $enriched = $original->withMetadata(new Metadata(correlationId: 'corr-1'));

        $this->assertNotSame($original, $enriched);
        $this->assertNull($original->metadata->correlationId);
        $this->assertSame('corr-1', $enriched->metadata->correlationId);
    }

    public function test_with_metadata_preserves_all_other_fields(): void
    {
        $original = $this->makeEnvelope();
        $enriched = $original->withMetadata(new Metadata(correlationId: 'corr-1', tenantId: 'tenant-9'));

        $this->assertSame($original->eventId, $enriched->eventId);
        $this->assertSame($original->aggregateId, $enriched->aggregateId);
        $this->assertSame($original->aggregateType, $enriched->aggregateType);
        $this->assertSame($original->aggregateVersion, $enriched->aggregateVersion);
        $this->assertSame($original->payloadType, $enriched->payloadType);
        $this->assertSame($original->schemaVersion, $enriched->schemaVersion);
        $this->assertSame($original->occurredAt, $enriched->occurredAt);
        $this->assertSame($original->payload, $enriched->payload);
    }

    public function test_envelope_is_readonly(): void
    {
        $envelope = $this->makeEnvelope();

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line — intentional assignment to verify readonly */
        $envelope->eventId = 'mutated';
    }

    public function test_payload_can_be_any_object(): void
    {
        $payload = new class {
            public readonly string $foo;
            public function __construct() { $this->foo = 'bar'; }
        };

        $envelope = new EventEnvelope(
            eventId:          'evt-1',
            aggregateId:      'agg-1',
            aggregateType:    'X',
            aggregateVersion: 1,
            payloadType:      get_class($payload),
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable(),
            payload:          $payload,
            metadata:         Metadata::empty(),
        );

        $this->assertSame($payload, $envelope->payload);
    }
}
