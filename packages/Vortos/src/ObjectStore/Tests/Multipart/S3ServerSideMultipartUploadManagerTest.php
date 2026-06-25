<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Multipart;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\Multipart\S3ServerSideMultipartUploadManager;
use Vortos\ObjectStore\ValueObject\ContentType;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\ServerSideMultipartUploadOptions;

final class S3ServerSideMultipartUploadManagerTest extends TestCase
{
    private function makeClient(MockHandler $handler): S3Client
    {
        return new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);
    }

    public function test_small_upload_uses_single_part_store(): void
    {
        $manager = new S3ServerSideMultipartUploadManager(
            $this->makeClient(new MockHandler()),
            new NullObjectStore(),
            'media',
            thresholdBytes: 10,
            partSizeBytes: 5242880,
        );

        $result = $manager->upload('small.txt', 'abc');

        $this->assertSame('small.txt', $result->key()->value());
        $this->assertSame(3, $result->size());
    }

    public function test_large_stream_upload_runs_multipart_sequence_with_checksum_and_progress(): void
    {
        $partSize = 5_242_880;

        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('CreateMultipartUpload', $cmd->getName());
            $this->assertSame('CRC32', $cmd['ChecksumAlgorithm']);
            $this->assertSame('application/octet-stream', $cmd['ContentType']);
            return new Result(['UploadId' => 'upload-1']);
        });
        $handler->append(function (CommandInterface $cmd) use ($partSize) {
            $this->assertSame('UploadPart', $cmd->getName());
            $this->assertSame(1, $cmd['PartNumber']);
            $body = $cmd['Body'];
            $len = \is_string($body) ? \strlen($body) : (int) $body->getSize();
            unset($body);
            $this->assertSame($partSize, $len);
            return new Result(['ETag' => '"part-1"']);
        });
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('UploadPart', $cmd->getName());
            $this->assertSame(2, $cmd['PartNumber']);
            $body = $cmd['Body'];
            $len = \is_string($body) ? \strlen($body) : (int) $body->getSize();
            unset($body);
            $this->assertSame(1, $len);
            return new Result(['ETag' => '"part-2"']);
        });
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('CompleteMultipartUpload', $cmd->getName());
            $this->assertSame([
                ['PartNumber' => 1, 'ETag' => '"part-1"'],
                ['PartNumber' => 2, 'ETag' => '"part-2"'],
            ], $cmd['MultipartUpload']['Parts']);
            return new Result(['ETag' => '"complete-etag"']);
        });

        $stream = $this->streamOfSize($partSize + 1);
        $progress = [];

        $manager = new S3ServerSideMultipartUploadManager(
            $this->makeClient($handler),
            new NullObjectStore(),
            'media',
            thresholdBytes: 1,
            partSizeBytes: $partSize,
            maxInlineBodyBytes: 1,
        );

        $result = $manager->upload(
            'large.bin',
            $stream,
            new PutObjectOptions(new ContentType('application/octet-stream')),
            new ServerSideMultipartUploadOptions(
                checksumAlgorithm: 'CRC32',
                onPartUploaded: static function (int $partNumber, int $bytes) use (&$progress): void {
                    $progress[] = [$partNumber, $bytes];
                },
            ),
        );

        $this->assertSame('complete-etag', $result->etag());
        $this->assertSame([[1, $partSize], [2, 1]], $progress);
    }

    public function test_failed_part_is_retried_before_abort(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['UploadId' => 'upload-1']));
        $handler->append(new AwsException(
            'part failed',
            new \Aws\Command('UploadPart'),
            ['code' => 'InternalError', 'message' => 'part failed', 'response' => ['status_code' => 500]],
        ));
        $handler->append(new AwsException(
            'part failed again',
            new \Aws\Command('UploadPart'),
            ['code' => 'InternalError', 'message' => 'part failed again', 'response' => ['status_code' => 500]],
        ));
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('AbortMultipartUpload', $cmd->getName());
            $this->assertSame('upload-1', $cmd['UploadId']);
            return new Result([]);
        });

        $manager = new S3ServerSideMultipartUploadManager(
            $this->makeClient($handler),
            new NullObjectStore(),
            'media',
            thresholdBytes: 1,
            partSizeBytes: 5_242_880,
            abortOnFailure: true,
            maxAttempts: 2,
            concurrency: 1,
            backoffBaseMilliseconds: 0,
            backoffCapMilliseconds: 0,
        );

        $this->expectException(ObjectStoreException::class);
        $manager->upload('large.bin', $this->streamOfSize(5_242_881));
    }

    public function test_large_inline_body_is_rejected(): void
    {
        $manager = new S3ServerSideMultipartUploadManager(
            $this->makeClient(new MockHandler()),
            new NullObjectStore(),
            'media',
            thresholdBytes: 1,
            partSizeBytes: 5242880,
            maxInlineBodyBytes: 8,
        );

        $this->expectException(ObjectStoreException::class);
        $this->expectExceptionMessage('Inline server-side multipart body exceeds');

        $manager->upload('large.bin', str_repeat('a', 32));
    }

    public function test_object_size_limit_is_enforced_before_provider_call(): void
    {
        $manager = new S3ServerSideMultipartUploadManager(
            $this->makeClient(new MockHandler()),
            new NullObjectStore(),
            'media',
            thresholdBytes: 1,
            partSizeBytes: 5242880,
            maxObjectSizeBytes: 8,
        );

        $this->expectException(ObjectStoreException::class);
        $this->expectExceptionMessage('exceeds configured server-side multipart limit');

        $manager->upload('large.bin', str_repeat('a', 32));
    }

    private function streamOfSize(int $bytes): mixed
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'rb+');
        $chunkSize = 65536;
        $chunk = str_repeat('a', $chunkSize);
        $written = 0;

        while ($written + $chunkSize <= $bytes) {
            fwrite($stream, $chunk);
            $written += $chunkSize;
        }

        if ($written < $bytes) {
            fwrite($stream, str_repeat('a', $bytes - $written));
        }

        rewind($stream);

        return $stream;
    }
}
