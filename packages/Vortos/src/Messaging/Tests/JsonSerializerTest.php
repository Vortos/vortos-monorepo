<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Serializer\JsonSerializer;
use Vortos\Messaging\Serializer\Exception\DeserializationException;

// Pure POPO — no base class, no interface
final readonly class PayloadWithScalars
{
    public function __construct(
        public string $name,
        public int $count,
        public bool $active = true,
    ) {}
}

final readonly class PayloadWithNested
{
    public function __construct(
        public string $title,
        public PayloadWithScalars $inner,
    ) {}
}

final readonly class PayloadWithOptional
{
    public function __construct(
        public string $required,
        public string $optional = 'default',
    ) {}
}

// Required-but-nullable: no default, so the constructor demands the argument,
// but the type permits null. Mirrors real domain events like PaymentCompleted
// ($transactionId) and InvitationSent ($personalMessage).
final readonly class PayloadWithRequiredNullable
{
    public function __construct(
        public string $id,
        public ?string $note,
        public ?PayloadWithScalars $inner,
    ) {}
}

final class JsonSerializerTest extends TestCase
{
    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    public function test_supports_json_format(): void
    {
        $this->assertTrue($this->serializer->supports('json'));
        $this->assertFalse($this->serializer->supports('xml'));
    }

    public function test_serialize_produces_valid_json(): void
    {
        $payload = new PayloadWithScalars('hello', 42);
        $json = $this->serializer->serialize($payload);

        $this->assertJson($json);
    }

    public function test_serialize_includes_only_public_properties(): void
    {
        $payload = new PayloadWithScalars('hello', 42, false);
        $data = json_decode($this->serializer->serialize($payload), true);

        $this->assertSame('hello', $data['name']);
        $this->assertSame(42, $data['count']);
        $this->assertFalse($data['active']);
        $this->assertCount(3, $data);
    }

    public function test_roundtrip_preserves_scalar_values(): void
    {
        $original = new PayloadWithScalars('roundtrip', 99, false);
        $json = $this->serializer->serialize($original);
        $restored = $this->serializer->deserialize($json, PayloadWithScalars::class);

        $this->assertInstanceOf(PayloadWithScalars::class, $restored);
        $this->assertSame('roundtrip', $restored->name);
        $this->assertSame(99, $restored->count);
        $this->assertFalse($restored->active);
    }

    public function test_deserialize_uses_default_for_optional_param(): void
    {
        $json = json_encode(['required' => 'value']);
        $result = $this->serializer->deserialize($json, PayloadWithOptional::class);

        $this->assertSame('value', $result->required);
        $this->assertSame('default', $result->optional);
    }

    public function test_deserialize_strips_legacy_class_field(): void
    {
        $json = json_encode(['_class' => 'Some\\OldClass', 'name' => 'x', 'count' => 1]);
        $result = $this->serializer->deserialize($json, PayloadWithScalars::class);

        $this->assertSame('x', $result->name);
    }

    public function test_deserialize_throws_for_unknown_class(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('{}', 'App\\NonExistent\\Event');
    }

    public function test_deserialize_throws_for_missing_required_param(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('{"count":1}', PayloadWithScalars::class);
    }

    public function test_deserialize_accepts_null_for_required_nullable_param(): void
    {
        // Regression: a present key with a null value is a supplied argument.
        // isset() treated `"note":null` as absent and threw for the required
        // (no-default) nullable param, failing the whole event.
        $json = json_encode(['id' => 'x', 'note' => null, 'inner' => null]);
        $result = $this->serializer->deserialize($json, PayloadWithRequiredNullable::class);

        $this->assertSame('x', $result->id);
        $this->assertNull($result->note);
        $this->assertNull($result->inner);
    }

    public function test_deserialize_preserves_non_null_nullable_values(): void
    {
        $json = json_encode(['id' => 'x', 'note' => 'hello', 'inner' => ['name' => 'n', 'count' => 3]]);
        $result = $this->serializer->deserialize($json, PayloadWithRequiredNullable::class);

        $this->assertSame('hello', $result->note);
        $this->assertInstanceOf(PayloadWithScalars::class, $result->inner);
        $this->assertSame('n', $result->inner->name);
    }

    public function test_deserialize_still_throws_when_required_key_absent(): void
    {
        // A genuinely absent key (not just null) must still fail for a no-default param.
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize(json_encode(['id' => 'x', 'note' => null]), PayloadWithRequiredNullable::class);
    }

    public function test_serialize_nested_object_roundtrip(): void
    {
        $inner = new PayloadWithScalars('inner', 7);
        $outer = new PayloadWithNested('outer', $inner);

        $json = $this->serializer->serialize($outer);
        $data = json_decode($json, true);

        $this->assertSame('outer', $data['title']);
        $this->assertIsArray($data['inner']);
        $this->assertSame('inner', $data['inner']['name']);
    }
}
