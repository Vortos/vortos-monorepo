<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use BackedEnum;
use UnitEnum;

/**
 * Normalises span-attribute values into OpenTelemetry-acceptable scalars before they reach the SDK.
 *
 * The framework's tracing adapters routinely tag spans with enum values — e.g.
 * `['vortos.module' => TracingModule::Cache]` across cache/persistence/messaging/auth/... The OTel SDK
 * only accepts scalars (and homogeneous arrays of scalars), so a raw enum is silently dropped with a
 * noisy `attribute with non-primitive or non-homogeneous array of primitives dropped` warning AND the
 * attribute is lost from the trace. Converting a {@see BackedEnum} to its `->value` (and a pure
 * {@see UnitEnum} to its `->name`) keeps the attribute and silences the warning — centrally, so none of
 * the dozens of call sites that tag spans with enums need to change.
 */
final class AttributeNormalizer
{
    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function normalizeAll(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $normalized[$key] = self::normalize($value);
        }

        return $normalized;
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
