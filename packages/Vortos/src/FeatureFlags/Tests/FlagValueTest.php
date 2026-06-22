<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exception\InvalidFlagValueException;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;

final class FlagValueTest extends TestCase
{
    // --- construction & accessors ---

    public function test_bool_value_round_trips(): void
    {
        $v = FlagValue::bool(true);
        $this->assertSame(FlagValueType::Bool, $v->type);
        $this->assertTrue($v->asBool());
        $this->assertSame(true, $v->raw());
    }

    public function test_string_value_round_trips(): void
    {
        $v = FlagValue::string('treatment-b');
        $this->assertSame(FlagValueType::String, $v->type);
        $this->assertSame('treatment-b', $v->asString());
    }

    public function test_number_always_stored_as_float(): void
    {
        $this->assertSame(42.0, FlagValue::number(42)->asNumber());
        $this->assertSame(3.5, FlagValue::number(3.5)->asNumber());
    }

    public function test_json_value_round_trips(): void
    {
        $v = FlagValue::json(['tier' => 'pro', 'limits' => ['seats' => 5]]);
        $this->assertSame(FlagValueType::Json, $v->type);
        $this->assertSame(['tier' => 'pro', 'limits' => ['seats' => 5]], $v->asJson());
    }

    public function test_null_json_is_allowed(): void
    {
        $v = FlagValue::json(null);
        $this->assertNull($v->asJson());
        $this->assertNull($v->encode());
    }

    // --- zero / safe defaults ---

    public function test_zero_values_per_type(): void
    {
        $this->assertSame(false, FlagValue::zero(FlagValueType::Bool)->raw());
        $this->assertSame('', FlagValue::zero(FlagValueType::String)->raw());
        $this->assertSame(0.0, FlagValue::zero(FlagValueType::Number)->raw());
        $this->assertNull(FlagValue::zero(FlagValueType::Json)->raw());
    }

    // --- of() coercion ---

    public function test_of_coerces_common_bool_encodings(): void
    {
        foreach (['1', 'true', 'on', 'yes', 1, true] as $truthy) {
            $this->assertTrue(FlagValue::of(FlagValueType::Bool, $truthy)->asBool(), var_export($truthy, true));
        }
        foreach (['0', 'false', 'off', 'no', '', 0, false] as $falsy) {
            $this->assertFalse(FlagValue::of(FlagValueType::Bool, $falsy)->asBool(), var_export($falsy, true));
        }
    }

    public function test_of_null_yields_zero(): void
    {
        $this->assertSame(0.0, FlagValue::of(FlagValueType::Number, null)->raw());
    }

    public function test_of_numeric_string_coerces_to_number(): void
    {
        $this->assertSame(12.5, FlagValue::of(FlagValueType::Number, '12.5')->asNumber());
    }

    public function test_of_non_numeric_string_rejected_for_number(): void
    {
        $this->expectException(InvalidFlagValueException::class);
        FlagValue::of(FlagValueType::Number, 'not-a-number');
    }

    public function test_of_non_array_rejected_for_json(): void
    {
        $this->expectException(InvalidFlagValueException::class);
        FlagValue::of(FlagValueType::Json, 'scalar');
    }

    // --- encode / decode storage round-trip ---

    public function test_encode_decode_round_trip_all_types(): void
    {
        $cases = [
            FlagValue::bool(true),
            FlagValue::bool(false),
            FlagValue::string('hello "world" /slash/ ünïcode'),
            FlagValue::number(99.99),
            FlagValue::json(['a' => 1, 'b' => [2, 3]]),
        ];

        foreach ($cases as $original) {
            $decoded = FlagValue::decode($original->type, $original->encode());
            $this->assertEquals($original->raw(), $decoded->raw());
        }
    }

    public function test_decode_null_hydrates_to_zero(): void
    {
        $this->assertSame(false, FlagValue::decode(FlagValueType::Bool, null)->raw());
        $this->assertSame('', FlagValue::decode(FlagValueType::String, null)->raw());
    }

    public function test_decode_corrupt_json_throws(): void
    {
        $this->expectException(InvalidFlagValueException::class);
        FlagValue::decode(FlagValueType::Json, '{not valid json');
    }

    public function test_encode_is_stable_for_same_value(): void
    {
        // Determinism underpins the wire `version` hash.
        $a = FlagValue::json(['x' => 1, 'y' => 2])->encode();
        $b = FlagValue::json(['x' => 1, 'y' => 2])->encode();
        $this->assertSame($a, $b);
    }

    // --- JSON bounds (security) ---

    public function test_oversized_json_rejected(): void
    {
        $big = ['blob' => str_repeat('a', FlagValue::MAX_JSON_BYTES + 1)];
        $this->expectException(InvalidFlagValueException::class);
        FlagValue::json($big);
    }

    public function test_json_at_size_limit_is_accepted(): void
    {
        $payload = ['blob' => str_repeat('a', 1000)];
        $v = FlagValue::json($payload);
        $this->assertSame($payload, $v->asJson());
    }

    public function test_too_deeply_nested_json_rejected(): void
    {
        // Build nesting beyond MAX_JSON_DEPTH.
        $deep = 'leaf';
        for ($i = 0; $i < FlagValue::MAX_JSON_DEPTH + 2; $i++) {
            $deep = [$deep];
        }

        $this->expectException(InvalidFlagValueException::class);
        FlagValue::json(['root' => $deep]);
    }
}
