<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\DirectUpload;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\DirectUpload\S3DirectUploadManager;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\Exception\PromotionRejectedException;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class S3DirectUploadManagerTest extends TestCase
{
    private function makeManager(MockHandler $handler): S3DirectUploadManager
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);

        return new S3DirectUploadManager($client, 'media', new NullObjectStore(), 'tmp');
    }

    public function test_create_upload_intent_requires_temporary_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeManager(new MockHandler())->createUploadIntent(
            'registrations/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(900, 'video/mp4', 100),
        );
    }

    public function test_create_upload_intent_returns_presigned_upload(): void
    {
        $intent = $this->makeManager(new MockHandler())->createUploadIntent(
            'tmp/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(900, 'video/mp4', 100),
        );

        $this->assertSame('tmp/video.mp4', $intent->temporaryKey()->value());
        $this->assertSame('video/mp4', $intent->constraints()->contentType()?->value());
    }

    public function test_promote_copies_then_deletes_temporary_source(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('CopyObject', $cmd->getName());
            $this->assertSame('registrations/video.mp4', $cmd['Key']);
            $this->assertSame(rawurlencode('media/tmp/video.mp4'), $cmd['CopySource']);
            return new Result(['CopyObjectResult' => ['ETag' => '"etag-promoted"']]);
        });
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('DeleteObject', $cmd->getName());
            $this->assertSame('tmp/video.mp4', $cmd['Key']);
            return new Result([]);
        });

        $result = $this->makeManager($handler)->promote(
            PromoteObjectRequest::fromKeys('tmp/video.mp4', 'registrations/video.mp4'),
        );

        $this->assertSame('registrations/video.mp4', $result->permanentKey()->value());
        $this->assertSame('etag-promoted', $result->storedObject()->etag());
        $this->assertTrue($result->temporaryDeleted());
    }

    public function test_promotion_policy_can_reject_before_copy(): void
    {
        $policy = new class implements ObjectPromotionPolicyInterface {
            public function assertCanPromote(PromoteObjectRequest $request): void
            {
                throw new PromotionRejectedException('Object is quarantined.');
            }
        };

        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => new MockHandler(),
            'retries' => 0,
        ]);

        $manager = new S3DirectUploadManager($client, 'media', new NullObjectStore(), 'tmp', $policy);

        $this->expectException(PromotionRejectedException::class);
        $this->expectExceptionMessage('Object is quarantined.');

        $manager->promote(PromoteObjectRequest::fromKeys('tmp/video.mp4', 'registrations/video.mp4'));
    }
}
