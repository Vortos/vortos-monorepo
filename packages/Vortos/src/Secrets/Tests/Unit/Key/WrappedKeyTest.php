<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Key;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Key\WrappedKey;

final class WrappedKeyTest extends TestCase
{
    public function test_constructs_with_ciphertext_and_recipient(): void
    {
        $wrapped = new WrappedKey('sealed-bytes', 'primary');

        self::assertSame('sealed-bytes', $wrapped->ciphertext);
        self::assertSame('primary', $wrapped->recipientId);
    }

    public function test_rejects_empty_ciphertext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WrappedKey('', 'primary');
    }

    public function test_rejects_empty_recipient_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WrappedKey('sealed-bytes', '');
    }
}
