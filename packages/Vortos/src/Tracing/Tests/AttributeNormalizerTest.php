<?php

declare(strict_types=1);

namespace Vortos\Tracing\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Tracing\OpenTelemetry\AttributeNormalizer;

enum NormalizerBackedFixture: string
{
    case Cache = 'cache';
}

enum NormalizerPureFixture
{
    case Alpha;
}

final class AttributeNormalizerTest extends TestCase
{
    public function test_backed_enum_becomes_its_scalar_value(): void
    {
        self::assertSame('cache', AttributeNormalizer::normalize(NormalizerBackedFixture::Cache));
    }

    public function test_pure_enum_becomes_its_name(): void
    {
        self::assertSame('Alpha', AttributeNormalizer::normalize(NormalizerPureFixture::Alpha));
    }

    public function test_scalars_and_null_pass_through_unchanged(): void
    {
        self::assertSame('x', AttributeNormalizer::normalize('x'));
        self::assertSame(42, AttributeNormalizer::normalize(42));
        self::assertTrue(AttributeNormalizer::normalize(true));
        self::assertNull(AttributeNormalizer::normalize(null));
    }

    public function test_arrays_are_normalized_element_wise(): void
    {
        self::assertSame(
            ['cache', 'Alpha', 'literal'],
            AttributeNormalizer::normalize([
                NormalizerBackedFixture::Cache,
                NormalizerPureFixture::Alpha,
                'literal',
            ]),
        );
    }

    public function test_normalize_all_converts_every_enum_value_in_an_attribute_map(): void
    {
        self::assertSame(
            ['vortos.module' => 'cache', 'http.status' => 200, 'ok' => true],
            AttributeNormalizer::normalizeAll([
                'vortos.module' => NormalizerBackedFixture::Cache,
                'http.status' => 200,
                'ok' => true,
            ]),
        );
    }
}
