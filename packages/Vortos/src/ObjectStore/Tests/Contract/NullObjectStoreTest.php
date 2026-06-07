<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class NullObjectStoreTest extends TestCase
{
    public function test_temporary_upload_url_preserves_direct_upload_constraints(): void
    {
        $store = new NullObjectStore();

        $upload = $store->temporaryUploadUrl(
            'tmp/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(
                ttlSeconds: 900,
                contentType: 'video/mp4',
                maxSizeBytes: 209715200,
            ),
        );

        $this->assertSame('tmp/video.mp4', $upload->key()->value());
        $this->assertSame('video/mp4', $upload->requiredHeaders()['Content-Type']);
        $this->assertSame(209715200, $upload->constraints()->maxSizeBytes());
    }

    public function test_post_policy_preserves_content_length_range(): void
    {
        $store = new NullObjectStore();

        $policy = $store->temporaryPostUpload(
            'tmp/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(
                ttlSeconds: 900,
                contentType: 'video/mp4',
                maxSizeBytes: 209715200,
            ),
        );

        $this->assertSame(['content-length-range', 0, 209715200], $policy->constraints()->postPolicyContentLengthRange());
        $this->assertSame('tmp/video.mp4', $policy->fields()['key']);
    }
}
