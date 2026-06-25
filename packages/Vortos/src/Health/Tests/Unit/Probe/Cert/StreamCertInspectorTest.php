<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Cert;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Cert\StreamCertInspector;

final class StreamCertInspectorTest extends TestCase
{
    public function testRejectsEmptyHost(): void
    {
        $result = (new StreamCertInspector())->inspect('');

        self::assertTrue($result->isFailure());
        self::assertSame('cert_invalid_target', $result->errorCode);
    }

    public function testRejectsOutOfRangePort(): void
    {
        $result = (new StreamCertInspector())->inspect('example.test', 0);

        self::assertTrue($result->isFailure());
        self::assertSame('cert_invalid_target', $result->errorCode);
    }

    public function testUnreachableHostDegradesToFailureNotException(): void
    {
        $result = (new StreamCertInspector(connectTimeoutSeconds: 1))
            ->inspect('this-host-does-not-exist.invalid', 443);

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->errorCode);
    }
}
