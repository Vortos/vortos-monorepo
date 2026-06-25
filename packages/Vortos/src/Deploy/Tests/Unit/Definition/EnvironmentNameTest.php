<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\EnvironmentName;

final class EnvironmentNameTest extends TestCase
{
    #[DataProvider('validNames')]
    public function test_accepts_valid_names(string $name): void
    {
        $env = new EnvironmentName($name);
        self::assertSame($name, $env->value);
    }

    /** @return iterable<string, list<string>> */
    public static function validNames(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'stage' => ['stage'];
        yield 'prod' => ['prod'];
        yield 'staging-2' => ['staging-2'];
        yield 'us-east-1' => ['us-east-1'];
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_invalid_names(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EnvironmentName($name);
    }

    /** @return iterable<string, list<string>> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['PROD'];
        yield 'space' => ['my env'];
        yield 'starts with digit' => ['1prod'];
        yield 'underscore' => ['my_env'];
        yield 'special' => ['prod!'];
    }

    public function test_equals(): void
    {
        $a = new EnvironmentName('prod');
        $b = new EnvironmentName('prod');
        $c = new EnvironmentName('dev');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function test_to_string(): void
    {
        $env = new EnvironmentName('staging');
        self::assertSame('staging', $env->toString());
    }
}
