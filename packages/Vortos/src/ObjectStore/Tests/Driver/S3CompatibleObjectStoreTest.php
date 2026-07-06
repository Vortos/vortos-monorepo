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
use Vortos\ObjectStore\Tests\Support\ChunkedNonSeekableStream;
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

    // ── Streaming uploads (STAGE-F-2): non-seekable, unknown-length bodies ──

    private function makeStreamingStore(MockHandler $handler, int $partSize): S3CompatibleObjectStore
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);

        return new S3CompatibleObjectStore($client, 'media', 'r2', $partSize);
    }

    /** @return resource */
    private function nonSeekablePipe(string $contents)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair, 'Could not create a socket pair for the non-seekable pipe test.');
        [$read, $write] = $pair;

        fwrite($write, $contents);
        fclose($write);
        self::assertFalse(stream_get_meta_data($read)['seekable'], 'Pipe under test must be non-seekable.');

        return $read;
    }

    public function test_put_non_seekable_pipe_that_fits_one_part_uses_single_put_object(): void
    {
        // Regression for STAGE-F-2: previously this threw "Unable to determine stream position".
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('PutObject', $cmd->getName());
            $this->assertSame('backups/db.dump', $cmd['Key']);
            $this->assertSame('PGDUMP-CUSTOM-BODY', (string) $cmd['Body']);

            return new Result(['ETag' => '"pipe-etag"', 'VersionId' => 'vp']);
        });

        $stored = $this->makeStore($handler)->put('backups/db.dump', $this->nonSeekablePipe('PGDUMP-CUSTOM-BODY'));

        $this->assertSame('pipe-etag', $stored->etag());
        $this->assertSame(18, $stored->size());
        $this->assertSame('vp', $stored->versionId());
    }

    public function test_put_stream_larger_than_one_part_uses_multipart_and_reports_bytes(): void
    {
        $partSize = 5_242_880;
        $body = str_repeat('A', $partSize) . str_repeat('B', 100);

        $commands = [];
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) use (&$commands) {
            $commands[] = $cmd->getName();
            $this->assertSame('CreateMultipartUpload', $cmd->getName());

            return new Result(['UploadId' => 'up-1']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$commands, $partSize) {
            $commands[] = $cmd->getName();
            $this->assertSame('UploadPart', $cmd->getName());
            $this->assertSame(1, $cmd['PartNumber']);
            $this->assertSame('up-1', $cmd['UploadId']);
            $this->assertSame($partSize, \strlen((string) $cmd['Body']), 'First part must be filled to the full part size.');

            return new Result(['ETag' => '"p1"']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$commands) {
            $commands[] = $cmd->getName();
            $this->assertSame('UploadPart', $cmd->getName());
            $this->assertSame(2, $cmd['PartNumber']);
            $this->assertSame(100, \strlen((string) $cmd['Body']), 'Last part carries the remainder.');

            return new Result(['ETag' => '"p2"']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$commands) {
            $commands[] = $cmd->getName();
            $this->assertSame('CompleteMultipartUpload', $cmd->getName());
            // Part ETags are passed to CompleteMultipartUpload verbatim (quotes included), exactly
            // as UploadPart returned them.
            $this->assertSame(
                [['PartNumber' => 1, 'ETag' => '"p1"'], ['PartNumber' => 2, 'ETag' => '"p2"']],
                $cmd['MultipartUpload']['Parts'],
            );

            return new Result(['ETag' => '"final-etag"', 'VersionId' => 'vfinal']);
        });

        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $body);
        rewind($stream);

        $stored = $this->makeStreamingStore($handler, $partSize)->put('backups/big.dump', $stream);

        $this->assertSame(
            ['CreateMultipartUpload', 'UploadPart', 'UploadPart', 'CompleteMultipartUpload'],
            $commands,
        );
        $this->assertSame('final-etag', $stored->etag());
        $this->assertSame($partSize + 100, $stored->size());
        $this->assertSame('vfinal', $stored->versionId());
    }

    public function test_put_non_seekable_short_read_stream_fills_full_parts(): void
    {
        // A pipe delivering only 4 KiB per read must still yield a full 5 MiB first part, never a
        // train of undersized parts that CompleteMultipartUpload would reject with EntityTooSmall.
        $partSize = 5_242_880;
        $body = str_repeat('X', $partSize) . str_repeat('Y', 4096);
        $stream = ChunkedNonSeekableStream::open($body, chunkSize: 4096);

        $partSizes = [];
        $handler = new MockHandler();
        $handler->append(new Result(['UploadId' => 'up-2']));
        $handler->append(function (CommandInterface $cmd) use (&$partSizes) {
            $partSizes[] = \strlen((string) $cmd['Body']);

            return new Result(['ETag' => '"p1"']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$partSizes) {
            $partSizes[] = \strlen((string) $cmd['Body']);

            return new Result(['ETag' => '"p2"']);
        });
        $handler->append(new Result(['ETag' => '"final"']));

        $stored = $this->makeStreamingStore($handler, $partSize)->put('backups/chunked.dump', $stream);

        $this->assertSame([$partSize, 4096], $partSizes);
        $this->assertSame($partSize + 4096, $stored->size());
    }

    public function test_put_stream_options_propagate_to_create_multipart_upload(): void
    {
        $partSize = 5_242_880;
        $body = str_repeat('A', $partSize + 10);

        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('CreateMultipartUpload', $cmd->getName());
            $this->assertSame('application/octet-stream', $cmd['ContentType']);
            $this->assertSame(['engine' => 'postgres'], $cmd['Metadata']);

            return new Result(['UploadId' => 'up-3']);
        });
        $handler->append(new Result(['ETag' => '"p1"']));
        $handler->append(new Result(['ETag' => '"p2"']));
        $handler->append(new Result(['ETag' => '"final"']));

        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $body);
        rewind($stream);

        $this->makeStreamingStore($handler, $partSize)->put(
            'backups/meta.dump',
            $stream,
            new PutObjectOptions(new ContentType('application/octet-stream'), ['engine' => 'postgres']),
        );
    }

    public function test_multipart_failure_aborts_upload_and_maps_exception(): void
    {
        $partSize = 5_242_880;
        $body = str_repeat('A', $partSize + 50);

        $aborted = false;
        $handler = new MockHandler();
        $handler->append(new Result(['UploadId' => 'up-4']));
        $handler->append(new Result(['ETag' => '"p1"']));
        $handler->append(new AwsException(
            'Part failed',
            new \Aws\Command('UploadPart'),
            ['code' => 'InternalError', 'message' => 'Part failed'],
        ));
        $handler->append(function (CommandInterface $cmd) use (&$aborted) {
            $this->assertSame('AbortMultipartUpload', $cmd->getName());
            $this->assertSame('up-4', $cmd['UploadId']);
            $aborted = true;

            return new Result([]);
        });

        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $body);
        rewind($stream);

        try {
            $this->makeStreamingStore($handler, $partSize)->put('backups/fail.dump', $stream);
            $this->fail('Expected an ObjectStoreException.');
        } catch (\Vortos\ObjectStore\Exception\ObjectStoreException $e) {
            $this->assertTrue($aborted, 'A failed multipart upload must be aborted.');
        }
    }
}
