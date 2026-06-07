<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\HttpMethod;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PresignedUploadUrl;
use Vortos\ObjectStore\ValueObject\PresignedUrl;
use Vortos\ObjectStore\ValueObject\UploadConstraints;

final class PresignedUploadUrlTest extends TestCase
{
    public function test_presigned_upload_url_requires_put(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PresignedUploadUrl(
            new ObjectKey('tmp/video.mp4'),
            new PresignedUrl('https://upload.example.test/tmp/video.mp4', HttpMethod::Get, new \DateTimeImmutable('+15 minutes')),
            UploadConstraints::forDirectUpload('video/mp4', 100),
        );
    }

    public function test_required_headers_merge_constraints_and_signed_headers(): void
    {
        $upload = new PresignedUploadUrl(
            new ObjectKey('tmp/video.mp4'),
            new PresignedUrl(
                'https://upload.example.test/tmp/video.mp4',
                HttpMethod::Put,
                new \DateTimeImmutable('+15 minutes'),
                ['x-amz-meta-form-id' => 'registration-1'],
            ),
            UploadConstraints::forDirectUpload('video/mp4', 100),
        );

        $this->assertSame('video/mp4', $upload->requiredHeaders()['Content-Type']);
        $this->assertSame('registration-1', $upload->requiredHeaders()['x-amz-meta-form-id']);
    }
}
