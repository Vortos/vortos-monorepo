<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;
use Vortos\ObjectStore\ValueObject\UploadMethod;

final class TemporaryUploadUrlOptionsTest extends TestCase
{
    public function test_defaults_to_signed_put_direct_upload(): void
    {
        $options = TemporaryUploadUrlOptions::forDirectUpload(
            ttlSeconds: 900,
            contentType: 'video/mp4',
            maxSizeBytes: 209715200,
        );

        $this->assertSame(UploadMethod::SignedPut, $options->method());
        $this->assertSame('video/mp4', $options->constraints()->contentType()?->value());
        $this->assertSame(209715200, $options->constraints()->maxSizeBytes());
        $this->assertGreaterThan(new \DateTimeImmutable('+14 minutes'), $options->expiresAt());
    }

    public function test_rejects_non_positive_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporaryUploadUrlOptions::forDirectUpload(ttlSeconds: 0);
    }
}
