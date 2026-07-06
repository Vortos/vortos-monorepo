<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Driver;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Port\BackupStream;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;

/**
 * End-to-end regression for STAGE-F-2: a live, non-seekable subprocess pipe (exactly what pg_dump
 * hands the runner) streams through {@see ObjectStoreBackupStore::store()} into the real S3 driver.
 *
 * Before the fix, the raw pipe reached a one-shot `PutObject` and the AWS SDK threw "Unable to
 * determine stream position". This drives the whole path — checksum read-filter over the pipe →
 * `put()` → upload — and asserts the bytes that landed and the checksum computed match the payload.
 */
final class ObjectStoreBackupStorePipeStreamingTest extends TestCase
{
    /** @var resource|null Kept alive for the test's duration so the pipe is not closed by GC. */
    private $process;

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function test_store_streams_a_non_seekable_subprocess_pipe(): void
    {
        $payload = 'PGDUMP--custom-artifact--0123456789';

        $captured = null;
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) use (&$captured): Result {
            $this->assertSame('PutObject', $cmd->getName());
            $captured = (string) $cmd['Body'];

            return new Result(['ETag' => '"pipe-etag"']);
        });

        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);

        $store = new ObjectStoreBackupStore(new S3CompatibleObjectStore($client, 'backups', 'r2'));

        $stored = $store->store($this->pipeBackupStream($payload), 'backups/db/full.dump');

        // The exact bytes produced by the subprocess reached the object store.
        $this->assertSame($payload, $captured);
        // The checksum read-filter observed the same bytes while they streamed through.
        $this->assertSame(\strlen($payload), $stored->sizeBytes);
        $this->assertSame('sha256:' . hash('sha256', $payload), (string) $stored->checksum);
    }

    private function pipeBackupStream(string $payload): BackupStream
    {
        // A real OS pipe: non-seekable, unknown length — like pg_dump's stdout.
        $process = proc_open(
            ['sh', '-c', 'printf %s ' . escapeshellarg($payload)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Could not spawn the pipe-producing subprocess.');
        // Retain the process handle: if it is GC'd, PHP closes the pipe out from under the reader.
        $this->process = $process;
        fclose($pipes[2]);

        self::assertFalse(stream_get_meta_data($pipes[1])['seekable'], 'Backup source pipe must be non-seekable.');

        return new BackupStream(
            $pipes[1],
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
            SourceRef::none(),
        );
    }
}
