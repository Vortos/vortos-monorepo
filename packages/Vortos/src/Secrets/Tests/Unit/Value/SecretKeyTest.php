<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretKey;

final class SecretKeyTest extends TestCase
{
    #[DataProvider('validKeys')]
    public function test_accepts_valid_keys(string $key): void
    {
        self::assertSame($key, SecretKey::fromString($key)->value());
    }

    /** @return iterable<string, array{string}> */
    public static function validKeys(): iterable
    {
        yield 'simple' => ['db-password'];
        yield 'with-dot' => ['db.password'];
        yield 'with-underscore' => ['db_password'];
        yield 'single-char' => ['a'];
        yield 'alphanumeric' => ['key123'];
        yield 'mixed' => ['a.b-c_d1'];
    }

    #[DataProvider('invalidKeys')]
    public function test_rejects_invalid_keys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        SecretKey::fromString($key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'leading-whitespace' => [' key'];
        yield 'trailing-whitespace' => ['key '];
        yield 'leading-digit' => ['1key'];
        yield 'uppercase' => ['Key'];
        yield 'path-traversal' => ['../etc/passwd'];
        yield 'path-traversal-mid' => ['key/../other'];
        yield 'slash' => ['key/name'];
        yield 'space-inside' => ['key name'];
        yield 'special-char' => ['key$name'];
    }

    public function test_to_env_var_uppercases_and_replaces_separators(): void
    {
        $key = SecretKey::fromString('db.write-password');

        self::assertSame('DB_WRITE_PASSWORD', $key->toEnvVar());
    }

    public function test_equals(): void
    {
        $a = SecretKey::fromString('same-key');
        $b = SecretKey::fromString('same-key');
        $c = SecretKey::fromString('other-key');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function test_to_string(): void
    {
        $key = SecretKey::fromString('my-key');

        self::assertSame('my-key', (string) $key);
    }
}
