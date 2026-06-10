<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Config;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Config\Env;

final class EnvTest extends TestCase
{
    public function test_string_without_default(): void
    {
        $this->assertSame('%env(KAFKA_BROKERS)%', Env::string('KAFKA_BROKERS')->toPlaceholder());
    }

    public function test_int_without_default(): void
    {
        $this->assertSame('%env(int:KAFKA_PARTITIONS)%', Env::int('KAFKA_PARTITIONS')->toPlaceholder());
    }

    public function test_int_with_default(): void
    {
        $env = Env::int('KAFKA_REPLICATION_FACTOR', default: 3);

        $this->assertSame(
            '%env(int:default:vortos.env_default.KAFKA_REPLICATION_FACTOR:KAFKA_REPLICATION_FACTOR)%',
            $env->toPlaceholder(),
        );
        $this->assertTrue($env->hasDefault());
        $this->assertSame(3, $env->default);
        $this->assertSame('vortos.env_default.KAFKA_REPLICATION_FACTOR', $env->defaultParameterName());
    }

    public function test_string_with_default(): void
    {
        $this->assertSame(
            '%env(default:vortos.env_default.BROKER:BROKER)%',
            Env::string('BROKER', default: 'kafka://localhost:9092')->toPlaceholder(),
        );
    }

    public function test_bool_and_float(): void
    {
        $this->assertSame('%env(bool:VERIFY)%', Env::bool('VERIFY')->toPlaceholder());
        $this->assertSame('%env(float:TIMEOUT)%', Env::float('TIMEOUT')->toPlaceholder());
    }

    public function test_invalid_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Env::string('NOT VALID');
    }

    public function test_empty_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Env::string('');
    }
}
