<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Export;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Export\PlaceholderTranslator;
use Vortos\Iac\Export\SpecValue;

final class PlaceholderTranslatorTest extends TestCase
{
    private function translate(mixed $value, array $parameters = []): mixed
    {
        $container = new ContainerBuilder();
        foreach ($parameters as $name => $v) {
            $container->setParameter($name, $v);
        }

        return PlaceholderTranslator::translate($value, $container, 'test');
    }

    public function test_literals_pass_through(): void
    {
        $this->assertSame(12, $this->translate(12));
        $this->assertSame('orders.placed', $this->translate('orders.placed'));
        $this->assertSame(true, $this->translate(true));
        $this->assertNull($this->translate(null));
        $this->assertSame('100%', $this->translate('100%'));
    }

    public function test_string_placeholder(): void
    {
        $spec = $this->translate('%env(KAFKA_BROKERS)%');

        $this->assertSame([
            'name' => 'kafka_brokers',
            'type' => 'string',
            'default' => null,
            'description' => 'From environment variable KAFKA_BROKERS.',
            'sensitive' => false,
        ], $spec[SpecValue::VAR]);
    }

    public function test_int_placeholder_with_default(): void
    {
        $spec = $this->translate(
            '%env(int:default:vortos.env_default.KAFKA_PARTITIONS:KAFKA_PARTITIONS)%',
            ['vortos.env_default.KAFKA_PARTITIONS' => 12],
        );

        $this->assertSame('number', $spec[SpecValue::VAR]['type']);
        $this->assertSame(12, $spec[SpecValue::VAR]['default']);
    }

    public function test_bool_and_float_types(): void
    {
        $this->assertSame('bool', $this->translate('%env(bool:FEATURE_X)%')[SpecValue::VAR]['type']);
        $this->assertSame('number', $this->translate('%env(float:RATE)%')[SpecValue::VAR]['type']);
    }

    public function test_secret_looking_names_are_sensitive(): void
    {
        $this->assertTrue($this->translate('%env(KAFKA_SASL_PASS)%')[SpecValue::VAR]['sensitive']);
        $this->assertTrue($this->translate('%env(API_TOKEN)%')[SpecValue::VAR]['sensitive']);
        $this->assertFalse($this->translate('%env(KAFKA_PARTITIONS)%')[SpecValue::VAR]['sensitive']);
    }

    public function test_composite_string_with_embedded_placeholder_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('embeds an env placeholder');

        $this->translate('prefix-%env(SUFFIX)%');
    }

    public function test_unknown_processor_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("processor 'base64'");

        $this->translate('%env(base64:BLOB)%');
    }
}
