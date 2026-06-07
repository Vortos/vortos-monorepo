<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Multipart;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Multipart\S3ServerSideMultipartMaintenance;

final class S3ServerSideMultipartMaintenanceTest extends TestCase
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

    public function test_lists_multipart_uploads(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('ListMultipartUploads', $cmd->getName());
            $this->assertSame('tmp/', $cmd['Prefix']);

            return new Result([
                'IsTruncated' => false,
                'Uploads' => [[
                    'Key' => 'tmp/a.bin',
                    'UploadId' => 'upload-1',
                    'Initiated' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                ]],
            ]);
        });

        $uploads = (new S3ServerSideMultipartMaintenance($this->makeClient($handler), 'media'))->list('tmp/');

        $this->assertCount(1, $uploads);
        $this->assertSame('tmp/a.bin', $uploads[0]->key);
        $this->assertSame('upload-1', $uploads[0]->uploadId);
    }

    public function test_abort_stale_only_aborts_old_uploads(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'IsTruncated' => false,
            'Uploads' => [
                ['Key' => 'tmp/old.bin', 'UploadId' => 'old', 'Initiated' => new \DateTimeImmutable('2026-01-01T00:00:00Z')],
                ['Key' => 'tmp/new.bin', 'UploadId' => 'new', 'Initiated' => new \DateTimeImmutable('2026-01-03T00:00:00Z')],
            ],
        ]));
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('AbortMultipartUpload', $cmd->getName());
            $this->assertSame('tmp/old.bin', $cmd['Key']);
            $this->assertSame('old', $cmd['UploadId']);

            return new Result([]);
        });

        $aborted = (new S3ServerSideMultipartMaintenance($this->makeClient($handler), 'media'))
            ->abortStale(new \DateTimeImmutable('2026-01-02T00:00:00Z'), 'tmp/');

        $this->assertSame(1, $aborted);
    }
}
