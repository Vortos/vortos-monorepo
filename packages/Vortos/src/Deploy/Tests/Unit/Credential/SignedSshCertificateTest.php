<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\SignedSshCertificate;
use Vortos\Secrets\Value\SecretValue;

final class SignedSshCertificateTest extends TestCase
{
    public function test_is_expired(): void
    {
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('-1 minute'),
            principals: ['deploy'],
            serial: 'serial-001',
        );

        $this->assertTrue($cert->isExpired(new \DateTimeImmutable()));
    }

    public function test_is_not_expired(): void
    {
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('+5 minutes'),
            principals: ['deploy'],
            serial: 'serial-001',
        );

        $this->assertFalse($cert->isExpired(new \DateTimeImmutable()));
    }

    public function test_ttl_seconds(): void
    {
        $validBefore = (new \DateTimeImmutable())->modify('+120 seconds');
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: $validBefore,
            principals: ['deploy'],
            serial: 'serial-001',
        );

        $ttl = $cert->ttlSeconds(new \DateTimeImmutable());
        $this->assertGreaterThanOrEqual(119, $ttl);
        $this->assertLessThanOrEqual(121, $ttl);
    }

    public function test_ttl_seconds_expired_returns_zero(): void
    {
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('-1 minute'),
            principals: ['deploy'],
            serial: 'serial-001',
        );

        $this->assertSame(0, $cert->ttlSeconds(new \DateTimeImmutable()));
    }

    public function test_rejects_empty_principals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one principal');

        new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('+5 minutes'),
            principals: [],
            serial: 'serial-001',
        );
    }

    public function test_rejects_empty_serial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serial must not be empty');

        new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('+5 minutes'),
            principals: ['deploy'],
            serial: '',
        );
    }

    public function test_cert_blob_is_secret_value(): void
    {
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: new \DateTimeImmutable('+5 minutes'),
            principals: ['deploy', 'staging'],
            serial: 'serial-001',
        );

        $this->assertSame('***', (string) $cert->certBlob);
        $this->assertCount(2, $cert->principals);
    }

    public function test_cert_ttl_within_300_seconds(): void
    {
        $validBefore = (new \DateTimeImmutable())->modify('+300 seconds');
        $cert = new SignedSshCertificate(
            certBlob: SecretValue::fromString('cert-blob'),
            validBefore: $validBefore,
            principals: ['deploy'],
            serial: 'serial-001',
        );

        $now = new \DateTimeImmutable();
        $this->assertLessThanOrEqual(300, $cert->ttlSeconds($now));
    }
}
