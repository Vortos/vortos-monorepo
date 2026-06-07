<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Exception\InvalidEmailAddressException;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class EmailAddressTest extends TestCase
{
    public function test_valid_address_accepted(): void
    {
        $addr = new EmailAddress('user@example.com');
        $this->assertSame('user@example.com', $addr->address());
    }

    public function test_address_is_normalized_to_lowercase(): void
    {
        $addr = new EmailAddress('User@EXAMPLE.COM');
        $this->assertSame('user@example.com', $addr->address());
    }

    public function test_address_is_trimmed(): void
    {
        $addr = new EmailAddress('  user@example.com  ');
        $this->assertSame('user@example.com', $addr->address());
    }

    public function test_display_name_stored(): void
    {
        $addr = new EmailAddress('user@example.com', 'John Doe');
        $this->assertSame('John Doe', $addr->name());
    }

    public function test_empty_name_stored_as_null(): void
    {
        $addr = new EmailAddress('user@example.com', '  ');
        $this->assertNull($addr->name());
    }

    public function test_null_name_stored_as_null(): void
    {
        $addr = new EmailAddress('user@example.com', null);
        $this->assertNull($addr->name());
    }

    public function test_to_string_without_name(): void
    {
        $addr = new EmailAddress('user@example.com');
        $this->assertSame('user@example.com', $addr->toString());
    }

    public function test_to_string_with_name(): void
    {
        $addr = new EmailAddress('user@example.com', 'John Doe');
        $this->assertSame('"John Doe" <user@example.com>', $addr->toString());
    }

    public function test_magic_to_string(): void
    {
        $addr = new EmailAddress('user@example.com', 'Jane');
        $this->assertSame('"Jane" <user@example.com>', (string) $addr);
    }

    public function test_equals_same_address(): void
    {
        $a = new EmailAddress('user@example.com', 'John');
        $b = new EmailAddress('user@example.com', 'Different Name');
        $this->assertTrue($a->equals($b));
    }

    public function test_equals_different_address(): void
    {
        $a = new EmailAddress('a@example.com');
        $b = new EmailAddress('b@example.com');
        $this->assertFalse($a->equals($b));
    }

    public function test_from_string_factory(): void
    {
        $addr = EmailAddress::fromString('user@example.com', 'Alice');
        $this->assertSame('user@example.com', $addr->address());
        $this->assertSame('Alice', $addr->name());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAddressProvider')]
    public function test_invalid_address_throws(string $address): void
    {
        $this->expectException(InvalidEmailAddressException::class);
        new EmailAddress($address);
    }

    public static function invalidAddressProvider(): array
    {
        return [
            'empty string'     => [''],
            'no at sign'       => ['notanemail'],
            'no domain'        => ['user@'],
            'no local part'    => ['@example.com'],
            'spaces in local'  => ['user name@example.com'],
            'double at'        => ['user@@example.com'],
            'whitespace only'  => ['   '],
        ];
    }
}
