<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\IssuedCredential;
use Vortos\Secrets\Value\SecretValue;

final class IssuedCredentialTest extends TestCase
{
    public function test_basic_construction(): void
    {
        $material = SecretValue::fromString('key-data');
        $expires = new \DateTimeImmutable('+1 hour');
        $cred = new IssuedCredential('ssh-key', $material, $expires, 'prod');

        self::assertSame('ssh-key', $cred->type);
        self::assertSame('prod', $cred->issuedFor);
    }

    public function test_empty_type_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IssuedCredential('', SecretValue::fromString('x'), new \DateTimeImmutable('+1 hour'));
    }

    public function test_is_expired(): void
    {
        $past = new \DateTimeImmutable('-1 hour');
        $cred = new IssuedCredential('ssh-key', SecretValue::fromString('x'), $past);

        self::assertTrue($cred->isExpired(new \DateTimeImmutable()));
    }

    public function test_not_expired(): void
    {
        $future = new \DateTimeImmutable('+1 hour');
        $cred = new IssuedCredential('ssh-key', SecretValue::fromString('x'), $future);

        self::assertFalse($cred->isExpired(new \DateTimeImmutable()));
    }
}
