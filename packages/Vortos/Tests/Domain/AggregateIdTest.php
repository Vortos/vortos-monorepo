<?php
declare(strict_types=1);

namespace Vortos\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Identity\AggregateId;

final class TestAggregateId extends AggregateId {}

final class AggregateIdTest extends TestCase
{
    public function test_generates_valid_uuid(): void
    {
        $id = TestAggregateId::generate();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id->toString()
        );
    }

    public function test_from_string_roundtrip(): void
    {
        $id = TestAggregateId::generate();
        $restored = TestAggregateId::fromString($id->toString());
        $this->assertTrue($id->equals($restored));
    }

    public function test_two_generated_ids_are_different(): void
    {
        $id1 = TestAggregateId::generate();
        $id2 = TestAggregateId::generate();
        $this->assertFalse($id1->equals($id2));
    }

    public function test_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = TestAggregateId::fromString($uuid);
        $id2 = TestAggregateId::fromString($uuid);
        $this->assertTrue($id1->equals($id2));
    }

    public function test_to_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = TestAggregateId::fromString($uuid);
        $this->assertSame($uuid, (string)$id);
    }

    public function test_throws_on_invalid_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestAggregateId::fromString('not-a-uuid');
    }
}
