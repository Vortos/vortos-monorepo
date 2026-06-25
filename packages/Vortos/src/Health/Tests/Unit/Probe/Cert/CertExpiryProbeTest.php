<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Cert;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Cert\CertExpiryProbe;
use Vortos\Health\Probe\Cert\CertInspectionResult;
use Vortos\Health\Probe\Cert\InMemoryCertInspector;
use Vortos\Health\Probe\ProbeStatus;

final class CertExpiryProbeTest extends TestCase
{
    public function testRejectsEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CertExpiryProbe(new InMemoryCertInspector(), '');
    }

    public function testRejectsOutOfRangePort(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CertExpiryProbe(new InMemoryCertInspector(), 'example.test', 0);
    }

    public function testHandshakeTimeoutFailsWithClearErrorCode(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::failure('cert_handshake_timeout'));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('cert_handshake_timeout', $result->errorCode);
    }

    public function testUnreachableHostFailsWithClearErrorCode(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::failure('cert_unreachable'));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('cert_unreachable', $result->errorCode);
    }

    public function testSelfSignedFailsWithClearErrorCode(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::failure('cert_self_signed'));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('cert_self_signed', $result->errorCode);
    }

    public function testHealthyCertPassesWithDaysUntilExpiryDetail(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::ok(90));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Pass, $result->status);
        self::assertSame(90, $result->detail['days_until_expiry']);
    }

    public function testNearExpiryWarnsWithoutFailingReadiness(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::ok(10));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('cert_near_expiry', $result->errorCode);
    }

    public function testCriticalExpiryFailsReadiness(): void
    {
        $inspector = (new InMemoryCertInspector())
            ->withResult('example.test', 443, CertInspectionResult::ok(0));
        $probe = new CertExpiryProbe($inspector, 'example.test');

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('cert_near_expiry_critical', $result->errorCode);
    }
}
