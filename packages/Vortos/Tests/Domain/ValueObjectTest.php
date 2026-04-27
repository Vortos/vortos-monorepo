<?php
declare(strict_types=1);

namespace Vortos\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\ValueObject\ValueObject;

final readonly class EmailValueObject extends ValueObject
{
    public function __construct(private string $email) {}

    public function equals(ValueObject $other): bool
    {
        return $other instanceof self && $this->email === $other->email;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}

final readonly class NameValueObject extends ValueObject
{
    public function __construct(private string $name) {}

    public function equals(ValueObject $other): bool
    {
        return $other instanceof self && $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

final class ValueObjectTest extends TestCase
{
    public function test_equal_value_objects(): void
    {
        $a = new EmailValueObject('test@example.com');
        $b = new EmailValueObject('test@example.com');
        $this->assertTrue($a->equals($b));
    }

    public function test_different_value_objects(): void
    {
        $a = new EmailValueObject('a@example.com');
        $b = new EmailValueObject('b@example.com');
        $this->assertFalse($a->equals($b));
    }

    public function test_to_string(): void
    {
        $vo = new EmailValueObject('test@example.com');
        $this->assertSame('test@example.com', (string)$vo);
    }

    public function test_not_equal_to_different_type(): void
    {
        $a = new EmailValueObject('test@example.com');
        $b = new NameValueObject('test@example.com');
        $this->assertFalse($a->equals($b));
    }
}
