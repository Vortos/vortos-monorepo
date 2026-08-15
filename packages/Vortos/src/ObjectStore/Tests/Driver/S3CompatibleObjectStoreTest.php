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

    public function test_presigned_download_signs_the_response_overrides(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([]));

        $url = $this->makeStore($handler)->temporaryDownloadUrl(
            'registrations/passport.pdf',
            new \DateTimeImmutable('+15 minutes'),
            GetObjectOptions::forceDownload('passport.pdf'),
        )->url();

        // Signed into the URL, so a recipient cannot strip the forced download by
        // editing it — the signature would stop matching.
        $this->assertStringContainsString('response-content-disposition=', $url);
        $this->assertStringContainsString(rawurlencode('attachment; filename="passport.pdf"'), $url);
        $this->assertStringContainsString('response-content-type=' . rawurlencode('application/octet-stream'), $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function test_presigned_download_without_options_sends_no_overrides(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([]));

        $url = $this->makeStore($handler)->temporaryDownloadUrl(
            'registrations/passport.pdf',
            new \DateTimeImmutable('+15 minutes'),
        )->url();

        $this->assertStringNotContainsString('response-content-disposition', $url);
        $this->assertStringNotContainsString('response-content-type', $url);
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
            $this->assertSame('PGDUMP-CUSTOM-BODY', $this->bodyContents($cmd['Body']));

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
            $this->assertSame($partSize, \strlen($this->bodyContents($cmd['Body'])), 'First part must be filled to the full part size.');

            return new Result(['ETag' => '"p1"']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$commands) {
            $commands[] = $cmd->getName();
            $this->assertSame('UploadPart', $cmd->getName());
            $this->assertSame(2, $cmd['PartNumber']);
            $this->assertSame(100, \strlen($this->bodyContents($cmd['Body'])), 'Last part carries the remainder.');

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
            $partSizes[] = \strlen($this->bodyContents($cmd['Body']));

            return new Result(['ETag' => '"p1"']);
        });
        $handler->append(function (CommandInterface $cmd) use (&$partSizes) {
            $partSizes[] = \strlen($this->bodyContents($cmd['Body']));

            return new Result(['ETag' => '"p2"']);
        });
        $handler->append(new Result(['ETag' => '"final"']));

        $stored = $this->makeStreamingStore($handler, $partSize)->put('backups/chunked.dump', $stream);

        $this->assertSame([$partSize, 4096], $partSizes);
        $this->assertSame($partSize + 4096, $stored->size());
    }

    public function test_multipart_upload_memory_does_not_scale_with_object_size(): void
    {
        // THE REGRESSION. Parts used to be accumulated into PHP strings, so peak memory was a
        // multiple of the part size — the growing buffer, the transient copy each `.=` makes, and
        // the SDK's copy of the finished string. Uploading a backup died on PHP's default 128 MiB
        // limit, and the size of the BACKUP was irrelevant: everything uploads in fixed-size parts.
        //
        // This asserts the property that fix bought: streaming a multi-part object costs
        // approximately nothing in resident memory, because each part is buffered through
        // php://temp and released before the next is read.
        $partSize = 5_242_880;
        $parts = 6; // ~30 MiB, comfortably more than any plausible per-part allowance
        $body = str_repeat('Z', $partSize * $parts);
        $stream = ChunkedNonSeekableStream::open($body, chunkSize: 65_536);

        $handler = new MockHandler();
        $handler->append(new Result(['UploadId' => 'up-mem']));
        for ($i = 0; $i < $parts; $i++) {
            $handler->append(new Result(['ETag' => '"p' . $i . '"']));
        }
        $handler->append(new Result(['ETag' => '"final"']));

        gc_collect_cycles();
        $before = memory_get_usage(true);

        $stored = $this->makeStreamingStore($handler, $partSize)->put('backups/large.dump', $stream);

        gc_collect_cycles();
        $growth = memory_get_usage(true) - $before;

        $this->assertSame($partSize * $parts, $stored->size());

        // One part is 5 MiB. If parts were still being held as strings this would grow by at least
        // that much; with the spill buffer the growth is dominated by php://temp's in-memory
        // threshold, far below a single part.
        $this->assertLessThan(
            $partSize,
            $growth,
            sprintf(
                'Uploading %d MiB grew resident memory by %d bytes — parts are being held in memory.',
                ($partSize * $parts) >> 20,
                $growth,
            ),
        );
    }

    public function test_multipart_upload_releases_each_part_buffer_before_reading_the_next(): void
    {
        // Spilling parts to php://temp only bounds cost if the handles are CLOSED as the upload
        // walks forward. Holding them would move the exhaustion from RAM to open file descriptors
        // and disk, which is not a fix. Asserted by observing that each part handle is no longer a
        // live resource once a later part is uploaded.
        $partSize = 5_242_880;
        $body = str_repeat('Q', $partSize * 3);
        $stream = ChunkedNonSeekableStream::open($body, chunkSize: 65_536);

        $seen = [];
        $handler = new MockHandler();
        $handler->append(new Result(['UploadId' => 'up-fd']));
        for ($i = 0; $i < 3; $i++) {
            $handler->append(function (CommandInterface $cmd) use (&$seen, $i) {
                $seen[] = $cmd['Body'];

                return new Result(['ETag' => '"p' . $i . '"']);
            });
        }
        $handler->append(new Result(['ETag' => '"final"']));

        $this->makeStreamingStore($handler, $partSize)->put('backups/fd.dump', $stream);

        $this->assertCount(3, $seen);
        // Every part handle except possibly the last must be closed by the time the upload ends.
        $stillOpen = array_filter($seen, static fn (mixed $h): bool => \is_resource($h));
        $this->assertLessThanOrEqual(
            1,
            \count($stillOpen),
            'Part buffers are not being released as the upload progresses.',
        );
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

    /**
     * Read an S3 command body regardless of whether it is a string or a stream.
     *
     * Stream uploads now pass a rewound `php://temp` handle rather than a PHP string, so that a
     * part never has to be materialised in memory. Casting a resource to string yields
     * "Resource id #N", which is why these assertions silently compared 17 bytes before.
     */
    private function bodyContents(mixed $body): string
    {
        if (\is_resource($body)) {
            $pos = ftell($body);
            rewind($body);
            $contents = stream_get_contents($body);
            if ($pos !== false) {
                fseek($body, $pos);
            }

            return $contents === false ? '' : $contents;
        }

        return (string) $body;
    }

    /**
     * stream() must NOT buffer the object into memory.
     *
     * It used to call get(), which runs the body through bodyToString() and writes the resulting
     * string into php://temp. That made peak memory the object size — twice — so a 100 MB
     * physical_base backup died with "Allowed memory size exhausted" against a 128 MB limit while
     * 2.5 MB logical dumps kept working. Base backups were therefore writable and unrestorable, and
     * the restore drills passed anyway because they only ever exercised the small artifacts.
     *
     * A body that throws on getContents() stands in for "too large to buffer": if stream() reads it
     * eagerly this test fails, which is exactly the regression to catch.
     */
    public function test_stream_does_not_buffer_the_body_into_memory(): void
    {
        $payload = 'streamed-not-buffered';

        $handler = new MockHandler();
        $handler->append(new Result([
            'Body' => new class($payload) implements \Psr\Http\Message\StreamInterface {
                private int $pos = 0;
                public function __construct(private string $data) {}
                public function getContents(): string
                {
                    throw new \LogicException('getContents() would buffer the whole object into memory');
                }
                public function __toString(): string
                {
                    throw new \LogicException('__toString() would buffer the whole object into memory');
                }
                public function read(int $length): string
                {
                    $chunk = substr($this->data, $this->pos, $length);
                    $this->pos += strlen($chunk);
                    return $chunk;
                }
                public function eof(): bool { return $this->pos >= strlen($this->data); }
                public function getSize(): ?int { return strlen($this->data); }
                public function tell(): int { return $this->pos; }
                public function isSeekable(): bool { return false; }
                public function seek(int $offset, int $whence = SEEK_SET): void {}
                public function rewind(): void { $this->pos = 0; }
                public function isWritable(): bool { return false; }
                public function write(string $string): int { return 0; }
                public function isReadable(): bool { return true; }
                public function close(): void {}
                public function detach() { return null; }
                public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
            },
        ]));

        $stream = $this->makeStore($handler)->stream('backups/large.tar');

        self::assertIsResource($stream, 'stream() must hand back a readable resource.');
        self::assertSame($payload, stream_get_contents($stream));
        fclose($stream);
    }
}
