<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Driver;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\Exception\ObjectStoreAccessDeniedException;
use Vortos\ObjectStore\Exception\ObjectStoreRateLimitException;
use Vortos\ObjectStore\ValueObject\ContentType;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\ListObjectsOptions;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class S3CompatibleObjectStoreTest extends TestCase
{
    private function makeStore(MockHandler $handler): S3CompatibleObjectStore
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);

        return new S3CompatibleObjectStore($client, 'media', 'r2');
    }

    public function test_put_object_maps_request_and_returns_stored_object(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('PutObject', $cmd->getName());
            $this->assertSame('media', $cmd['Bucket']);
            $this->assertSame('tmp/video.mp4', $cmd['Key']);
            $this->assertSame('video/mp4', $cmd['ContentType']);
            $this->assertSame(['form' => 'registration'], $cmd['Metadata']);

            return new Result(['ETag' => '"etag-1"', 'VersionId' => 'v1']);
        });

        $result = $this->makeStore($handler)->put(
            'tmp/video.mp4',
            'abc',
            new PutObjectOptions(new ContentType('video/mp4'), ['form' => 'registration']),
        );

        $this->assertSame('tmp/video.mp4', $result->key()->value());
        $this->assertSame('etag-1', $result->etag());
        $this->assertSame(3, $result->size());
        $this->assertSame('v1', $result->versionId());
    }

    public function test_get_object_maps_range_and_returns_body(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('GetObject', $cmd->getName());
            $this->assertSame('bytes=10-20', $cmd['Range']);

            return new Result(['Body' => 'payload']);
        });

        $body = $this->makeStore($handler)->get(
            'registrations/video.mp4',
            new GetObjectOptions(new \Vortos\ObjectStore\ValueObject\ByteRange(10, 20)),
        );

        $this->assertSame('payload', $body->contents());
    }

    public function test_head_object_maps_metadata(): void
    {
        $modified = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $handler = new MockHandler();
        $handler->append(new Result([
            'ContentLength' => 123,
            'ContentType' => 'application/pdf',
            'ETag' => '"etag-head"',
            'LastModified' => $modified,
            'Metadata' => ['kind' => 'certificate'],
        ]));

        $metadata = $this->makeStore($handler)->head('docs/cert.pdf');

        $this->assertSame(123, $metadata->size());
        $this->assertSame('application/pdf', $metadata->contentType()?->value());
        $this->assertSame('etag-head', $metadata->etag());
        $this->assertSame(['kind' => 'certificate'], $metadata->metadata());
        $this->assertSame($modified, $metadata->lastModified());
    }

    public function test_exists_returns_false_for_missing_object(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Not found',
            new \Aws\Command('HeadObject'),
            ['code' => 'NoSuchKey', 'message' => 'Not found'],
        ));

        $this->assertFalse($this->makeStore($handler)->exists('missing.txt'));
    }

    public function test_delete_many_maps_partial_failures(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('DeleteObjects', $cmd->getName());
            $this->assertSame([['Key' => 'a.txt'], ['Key' => 'b.txt']], $cmd['Delete']['Objects']);

            return new Result(['Errors' => [['Key' => 'b.txt']]]);
        });

        $result = $this->makeStore($handler)->deleteMany(['a.txt', 'b.txt']);

        $this->assertSame(1, $result->deletedCount());
        $this->assertTrue($result->results()[0]->deleted());
        $this->assertFalse($result->results()[1]->deleted());
    }

    public function test_copy_maps_source_and_target(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('CopyObject', $cmd->getName());
            $this->assertSame('final/video.mp4', $cmd['Key']);
            $this->assertSame(rawurlencode('media/tmp/video.mp4'), $cmd['CopySource']);
            $this->assertSame('REPLACE', $cmd['MetadataDirective']);

            return new Result(['CopyObjectResult' => ['ETag' => '"etag-copy"']]);
        });

        $result = $this->makeStore($handler)->copy(
            'tmp/video.mp4',
            'final/video.mp4',
            new CopyObjectOptions(['promoted' => 'true'], true),
        );

        $this->assertSame('final/video.mp4', $result->key()->value());
        $this->assertSame('etag-copy', $result->etag());
    }

    public function test_list_maps_pagination(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('ListObjectsV2', $cmd->getName());
            $this->assertSame('registrations/', $cmd['Prefix']);
            $this->assertSame('token-1', $cmd['ContinuationToken']);
            $this->assertSame(50, $cmd['MaxKeys']);

            return new Result([
                'IsTruncated' => true,
                'NextContinuationToken' => 'token-2',
                'Contents' => [
                    ['Key' => 'registrations/a.pdf', 'Size' => 12, 'ETag' => '"etag-list"'],
                ],
            ]);
        });

        $listing = $this->makeStore($handler)->list(new ListObjectsOptions('registrations/', null, 'token-1', 50));

        $this->assertTrue($listing->truncated());
        $this->assertSame('token-2', $listing->nextContinuationToken());
        $this->assertSame('registrations/a.pdf', $listing->objects()[0]->key()->value());
        $this->assertSame('etag-list', $listing->objects()[0]->etag());
    }

    public function test_temporary_upload_url_signs_put_object_and_required_headers(): void
    {
        $handler = new MockHandler();
        $store = $this->makeStore($handler);

        $upload = $store->temporaryUploadUrl(
            'tmp/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(900, 'video/mp4', 209715200),
        );

        $this->assertSame('tmp/video.mp4', $upload->key()->value());
        $this->assertSame('video/mp4', $upload->requiredHeaders()['Content-Type']);
        $this->assertStringContainsString('X-Amz-Signature=', $upload->url()->url());
    }

    public function test_temporary_post_upload_includes_content_length_range(): void
    {
        $handler = new MockHandler();
        $store = $this->makeStore($handler);

        $policy = $store->temporaryPostUpload(
            'tmp/video.mp4',
            TemporaryUploadUrlOptions::forDirectUpload(900, 'video/mp4', 209715200),
        );

        $this->assertSame('tmp/video.mp4', $policy->key()->value());
        $this->assertSame('tmp/video.mp4', $policy->fields()['key']);
        $this->assertSame(['content-length-range', 0, 209715200], $policy->constraints()->postPolicyContentLengthRange());
        $this->assertArrayHasKey('Policy', $policy->fields());
    }

    public function test_missing_key_maps_to_object_not_found_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Not found',
            new \Aws\Command('GetObject'),
            ['code' => 'NoSuchKey', 'message' => 'Not found'],
        ));

        $this->expectException(ObjectNotFoundException::class);
        $this->makeStore($handler)->get('missing.txt');
    }

    public function test_access_denied_maps_to_access_denied_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Denied',
            new \Aws\Command('PutObject'),
            ['code' => 'AccessDenied', 'message' => 'Denied'],
        ));

        $this->expectException(ObjectStoreAccessDeniedException::class);
        $this->makeStore($handler)->put('a.txt', 'a');
    }

    public function test_slow_down_maps_to_rate_limit_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Slow down',
            new \Aws\Command('PutObject'),
            ['code' => 'SlowDown', 'message' => 'Slow down'],
        ));

        $this->expectException(ObjectStoreRateLimitException::class);
        $this->makeStore($handler)->put('a.txt', 'a');
    }
}
