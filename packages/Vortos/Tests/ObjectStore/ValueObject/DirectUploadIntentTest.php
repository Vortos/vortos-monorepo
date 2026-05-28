<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\HttpMethod;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PresignedUploadUrl;
use Vortos\ObjectStore\ValueObject\PresignedUrl;
use Vortos\ObjectStore\ValueObject\UploadConstraints;

final class DirectUploadIntentTest extends TestCase
{
    public function test_intent_requires_temporary_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DirectUploadIntent(
            new ObjectKey('registrations/video.mp4'),
            $this->upload('registrations/video.mp4'),
        );
    }

    public function test_intent_exposes_upload_constraints_for_browser_client(): void
    {
        $intent = new DirectUploadIntent(
            new ObjectKey('tmp/video.mp4'),
            $this->upload('tmp/video.mp4'),
        );

        $this->assertSame('tmp/video.mp4', $intent->temporaryKey()->value());
        $this->assertSame('tmp', $intent->temporaryPrefix());
        $this->assertSame('video/mp4', $intent->constraints()->contentType()?->value());
        $this->assertFalse($intent->expired(new \DateTimeImmutable('+5 minutes')));
    }

    private function upload(string $key): PresignedUploadUrl
    {
        return new PresignedUploadUrl(
            new ObjectKey($key),
            new PresignedUrl(
                'https://upload.example.test/' . rawurlencode($key),
                HttpMethod::Put,
                new \DateTimeImmutable('+15 minutes'),
            ),
            UploadConstraints::forDirectUpload('video/mp4', 209715200),
        );
    }
}
