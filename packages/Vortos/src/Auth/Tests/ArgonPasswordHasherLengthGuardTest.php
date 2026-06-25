<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Hasher\ArgonPasswordHasher;

final class ArgonPasswordHasherLengthGuardTest extends TestCase
{
    private ArgonPasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ArgonPasswordHasher(memoryCost: 1024, timeCost: 1);
    }

    public function test_hash_accepts_password_within_limit(): void
    {
        $password = str_repeat('a', 4096);
        $hash = $this->hasher->hash($password);
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_hash_rejects_password_exceeding_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');
        $this->hasher->hash(str_repeat('a', 4097));
    }

    public function test_verify_rejects_password_exceeding_limit(): void
    {
        $hash = $this->hasher->hash('short');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');
        $this->hasher->verify(str_repeat('a', 4097), $hash);
    }

    public function test_verify_accepts_password_within_limit(): void
    {
        $password = str_repeat('b', 4096);
        $hash = $this->hasher->hash($password);
        $this->assertTrue($this->hasher->verify($password, $hash));
    }

    public function test_hash_rejects_multibyte_oversized_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 2049 two-byte chars = 4098 bytes, exceeds 4096 byte limit
        $this->hasher->hash(str_repeat("\xC3\xA9", 2049));
    }
}
