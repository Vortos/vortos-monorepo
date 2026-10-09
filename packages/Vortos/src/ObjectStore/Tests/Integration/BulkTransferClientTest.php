<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Integration;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\S3\S3ClientFactory;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
use Vortos\ObjectStore\Exception\ObjectStoreException;

/**
 * The transfer rules on a real socket, against a local throttled S3 endpoint (Fixtures/throttled_s3_router.php).
 *
 * Unit tests prove the options are SET; only real curl proves what they DO. This reproduces the
 * production failure — a whole object as one GET through a short request timeout dies mid-transfer
 * with cURL error 28 — and shows each half of the fix on the same wire: ranged parts keep every
 * request inside a budget, and the bulk client finishes a long transfer that keeps moving and still
 * ends one that has stopped.
 */
final class BulkTransferClientTest extends TestCase
{
    private const OBJECT_BYTES = 3_000_000;
    private const BYTES_PER_SECOND = 1_000_000;

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('proc_open') || !\extension_loaded('curl')) {
            self::markTestSkipped('Needs proc_open and ext-curl.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$log = tempnam(sys_get_temp_dir(), 'throttled-s3-');

        self::$server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Fixtures/throttled_s3_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4', 'THROTTLED_S3_LOG' => self::$log],
        );

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }

        self::fail('The throttled S3 fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    public function test_one_whole_object_get_through_a_short_request_timeout_dies_mid_transfer(): void
    {
        $client = $this->applicationClient(requestTimeout: 1.0);

        try {
            $client->getObject(['Bucket' => 'backups', 'Key' => $this->trickleKey()]);
            self::fail('A 3 s transfer must not fit a 1 s request timeout.');
        } catch (\Aws\Exception\AwsException $e) {
            self::assertStringContainsString('cURL error 28', $e->getMessage());
        }
    }

    public function test_ranged_parts_keep_every_request_inside_a_short_request_timeout(): void
    {
        // 256 KiB parts at 1 MB/s take ~0.3 s each, so the same 1 s client now reads the 3 s object.
        $store = new S3CompatibleObjectStore($this->applicationClient(requestTimeout: 1.0), 'backups', 'generic_s3', 5_242_880, 262_144, 2);

        $stream = $store->stream($this->trickleKey());

        self::assertSame(self::expected(self::OBJECT_BYTES), stream_get_contents($stream));
        fclose($stream);
        self::assertCount(12, $this->servedRanges());
    }

    public function test_the_bulk_client_finishes_a_long_transfer_that_keeps_moving(): void
    {
        // One part — the whole 3 s object in a single request — so only the client's rules decide.
        $store = new S3CompatibleObjectStore($this->bulkClient(stallSeconds: 2), 'backups', 'generic_s3', 5_242_880, 8_388_608, 1);

        $started = microtime(true);
        $stream = $store->stream($this->trickleKey());
        $elapsed = microtime(true) - $started;

        self::assertSame(self::expected(self::OBJECT_BYTES), stream_get_contents($stream));
        fclose($stream);
        self::assertGreaterThan(2.0, $elapsed, 'the transfer must outlast the stall window to prove anything');
        self::assertSame(['bytes=0-8388607'], $this->servedRanges());
    }

    public function test_the_bulk_client_ends_a_transfer_that_has_stalled(): void
    {
        $store = new S3CompatibleObjectStore($this->bulkClient(stallSeconds: 2), 'backups', 'generic_s3', 5_242_880, 8_388_608, 1);

        $started = microtime(true);
        try {
            $store->stream('stall-100000');
            self::fail('A silent connection must not be waited on for ever.');
        } catch (ObjectStoreException $e) {
            self::assertStringContainsString('cURL error 28', $e->getMessage());
        }

        self::assertLessThan(10.0, microtime(true) - $started);
    }

    private function applicationClient(float $requestTimeout): S3Client
    {
        return S3ClientFactory::create('generic_s3', 'auto', $this->endpoint(), null, 'key', 'secret', $requestTimeout, 1.0, 1, true);
    }

    private function bulkClient(int $stallSeconds): S3Client
    {
        return S3ClientFactory::createForBulkTransfer('generic_s3', 'auto', $this->endpoint(), null, 'key', 'secret', 1.0, $stallSeconds, 1024, 1, true);
    }

    private function endpoint(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    private function trickleKey(): string
    {
        return sprintf('trickle-%d-%d', self::OBJECT_BYTES, self::BYTES_PER_SECOND);
    }

    /** @return list<string> */
    private function servedRanges(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents(self::$log))));
    }

    private static function expected(int $bytes): string
    {
        $out = '';
        for ($i = 0; $i < $bytes; $i++) {
            $out .= \chr($i % 251);
        }

        return $out;
    }
}
