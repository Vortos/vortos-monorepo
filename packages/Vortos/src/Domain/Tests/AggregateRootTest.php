<?php

declare(strict_types=1);

namespace Vortos\Domain\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Aggregate\Exception\InvalidEventPayloadException;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Domain\Identity\AggregateId;

// ---------- valid payload fixtures ----------

final readonly class EmptyValidPayload {}

final readonly class ValidPayloadWithData
{
    public function __construct(
        public string $name,
    ) {}
}

final readonly class ValidPayloadMultiField
{
    public function __construct(
        public string $email,
        public int $count,
    ) {}
}

// ---------- invalid payload fixtures ----------

// F1 violation — not final
class NonFinalPayload
{
    public function __construct(public readonly string $x = 'x') {}
}

// F3 violation — own method other than __construct
final readonly class PayloadWithMethod
{
    public function __construct(public string $x = 'x') {}
    public function describe(): string { return $this->x; }
}

// F3 violation — inherited methods (extends a class with methods)
class PayloadParentWithMethod
{
    public function inherited(): string { return 'inherited'; }
}
final class PayloadInheritsMethod extends PayloadParentWithMethod {}

// F2 violation — non-readonly property
final class PayloadWithMutableProp
{
    public function __construct(public string $x = 'x') {}
}

// F2 violation — non-public (private) property
final class PayloadWithPrivateProp
{
    public function __construct(private readonly string $x = 'x') {}
}

// F2 violation — non-promoted property
final readonly class PayloadWithNonPromotedProp
{
    public string $x;
    public function __construct(string $x)
    {
        $this->x = $x;
    }
}

// ---------- aggregate under test ----------

final class AggregateRootTestId extends AggregateId {}

final class TestAggregateForAggregateRootTest extends AggregateRoot
{
    private AggregateRootTestId $id;

    public function __construct()
    {
        $this->id = AggregateRootTestId::generate();
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }

    public function doSomething(): void
    {
        $this->recordEvent(new EmptyValidPayload());
    }

    public function doSomethingWithData(string $name): void
    {
        $this->recordEvent(new ValidPayloadWithData($name));
    }

    /** Expose recordEvent for direct testing of validation. */
    public function recordPublic(object $payload): void
    {
        $this->recordEvent($payload);
    }
}

// ---------- tests ----------

final class AggregateRootTest extends TestCase
{
    public function test_record_event_wraps_payload_in_envelope(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        $envelopes = $aggregate->pullDomainEvents();
        $this->assertCount(1, $envelopes);
        $this->assertInstanceOf(EventEnvelope::class, $envelopes[0]);
    }

    public function test_envelope_carries_aggregate_id(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertSame((string) $aggregate->getId(), $envelope->aggregateId);
    }

    public function test_envelope_carries_aggregate_type_as_static_class(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertSame(TestAggregateForAggregateRootTest::class, $envelope->aggregateType);
    }

    public function test_envelope_event_id_is_uuid_v7(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $envelope->eventId,
        );
    }

    public function test_envelope_occurred_at_is_recent(): void
    {
        $before = new \DateTimeImmutable();
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();
        $after = new \DateTimeImmutable();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertGreaterThanOrEqual($before, $envelope->occurredAt);
        $this->assertLessThanOrEqual($after, $envelope->occurredAt);
    }

    public function test_envelope_aggregate_version_is_current_plus_one(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertSame(1, $envelope->aggregateVersion);

        $aggregate->incrementVersion();
        $aggregate->doSomething();
        [$envelope2] = $aggregate->pullDomainEvents();
        $this->assertSame(2, $envelope2->aggregateVersion);
    }

    public function test_envelope_payload_type_is_payload_class(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomethingWithData('Sacin');

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertSame(ValidPayloadWithData::class, $envelope->payloadType);
    }

    public function test_envelope_default_schema_version_is_one(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertSame(1, $envelope->schemaVersion);
    }

    public function test_envelope_default_metadata_is_empty(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertEquals(Metadata::empty(), $envelope->metadata);
    }

    public function test_envelope_preserves_payload_instance(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomethingWithData('Sacin');

        [$envelope] = $aggregate->pullDomainEvents();
        $this->assertInstanceOf(ValidPayloadWithData::class, $envelope->payload);
        $this->assertSame('Sacin', $envelope->payload->name);
    }

    public function test_pull_returns_envelopes_and_clears(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();

        $first  = $aggregate->pullDomainEvents();
        $second = $aggregate->pullDomainEvents();

        $this->assertCount(1, $first);
        $this->assertEmpty($second);
    }

    public function test_multiple_envelopes_have_distinct_event_ids(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();
        $aggregate->doSomething();
        $aggregate->doSomething();

        $envelopes = $aggregate->pullDomainEvents();
        $eventIds = array_map(fn(EventEnvelope $e) => $e->eventId, $envelopes);
        $this->assertCount(3, array_unique($eventIds));
    }

    public function test_multiple_envelopes_share_aggregate_version(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();
        $aggregate->doSomething();

        $envelopes = $aggregate->pullDomainEvents();
        $this->assertSame($envelopes[0]->aggregateVersion, $envelopes[1]->aggregateVersion);
    }

    public function test_has_domain_events_true_after_record(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();
        $this->assertTrue($aggregate->hasDomainEvents());
    }

    public function test_has_domain_events_false_after_pull(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->doSomething();
        $aggregate->pullDomainEvents();
        $this->assertFalse($aggregate->hasDomainEvents());
    }

    public function test_version_starts_at_zero(): void
    {
        $this->assertSame(0, (new TestAggregateForAggregateRootTest())->getVersion());
    }

    public function test_version_increments(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->incrementVersion();
        $aggregate->incrementVersion();
        $this->assertSame(2, $aggregate->getVersion());
    }

    public function test_get_id_returns_aggregate_id(): void
    {
        $this->assertInstanceOf(
            AggregateId::class,
            (new TestAggregateForAggregateRootTest())->getId(),
        );
    }

    // ---------- F1: not final ----------

    public function test_rejects_non_final_payload(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/must be declared `final`/');
        $aggregate->recordPublic(new NonFinalPayload());
    }

    // ---------- F3: methods ----------

    public function test_rejects_payload_with_own_method(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/describe\(\)/');
        $aggregate->recordPublic(new PayloadWithMethod());
    }

    public function test_rejects_payload_with_inherited_method(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/inherited\(\)/');
        $aggregate->recordPublic(new PayloadInheritsMethod());
    }

    // ---------- F2: properties ----------

    public function test_rejects_payload_with_non_readonly_property(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/not readonly/');
        $aggregate->recordPublic(new PayloadWithMutableProp());
    }

    public function test_rejects_payload_with_non_public_property(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/not public/');
        $aggregate->recordPublic(new PayloadWithPrivateProp());
    }

    public function test_rejects_payload_with_non_promoted_property(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();

        $this->expectException(InvalidEventPayloadException::class);
        $this->expectExceptionMessageMatches('/not constructor-promoted/');
        $aggregate->recordPublic(new PayloadWithNonPromotedProp('x'));
    }

    // ---------- valid payloads pass ----------

    public function test_accepts_empty_payload(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->recordPublic(new EmptyValidPayload());
        $this->assertCount(1, $aggregate->pullDomainEvents());
    }

    public function test_accepts_payload_with_multiple_fields(): void
    {
        $aggregate = new TestAggregateForAggregateRootTest();
        $aggregate->recordPublic(new ValidPayloadMultiField('a@b.com', 7));
        [$envelope] = $aggregate->pullDomainEvents();

        $this->assertSame('a@b.com', $envelope->payload->email);
        $this->assertSame(7, $envelope->payload->count);
    }
}
