<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Driver;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\ValueObject\ByteRange;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;

/**
 * stream() reads a whole object as byte-range parts (S3RangedDownloader).
 *
 * The incident behind it: a 357 MB base backup was one GetObject through a client with a 5 s request
 * timeout, so the restore drill and the post-upload checksum read failed whenever R2 was a little
 * slow — mid-transfer, after most of the object had arrived — and a restore would have too.
 */
final class S3RangedDownloadTest extends TestCase
{
    private const PART = 10;

    /** @var list<array{range: ?string, ifMatch: ?string, disposition: ?string}> */
    private array $requests = [];

    public function test_a_small_object_is_one_ranged_request_and_stays_seekable(): void
    {
        $handler = new MockHandler();
        $handler->append($this->partResponder('tiny', 'etag-1'));

        $stream = $this->store($handler)->stream('backups/tiny.bin');

        self::assertSame([['range' => 'bytes=0-9', 'ifMatch' => null, 'disposition' => null]], $this->requests);
        self::assertSame('tiny', stream_get_contents($stream));

        // The WAL fetcher and archiver peek at magic bytes and rewind; that must keep working.
        rewind($stream);
        self::assertSame('ti', fread($stream, 2));
        fclose($stream);
    }

    public function test_a_large_object_is_assembled_from_parts_in_order_pinned_to_the_first_etag(): void
    {
        $object = str_repeat('0123456789', 3) . 'abcde'; // 35 bytes: 3 full parts + a 5-byte tail

        $handler = new MockHandler();
        for ($i = 0; $i < 4; $i++) {
            $handler->append($this->partResponder($object, 'etag-v1'));
        }

        $stream = $this->store($handler, concurrency: 2)->stream('backups/base.tar');

        self::assertSame($object, stream_get_contents($stream));
        self::assertSame(
            ['bytes=0-9', 'bytes=10-19', 'bytes=20-29', 'bytes=30-34'],
            array_column($this->requests, 'range'),
        );
        self::assertSame([null, 'etag-v1', 'etag-v1', 'etag-v1'], array_column($this->requests, 'ifMatch'));
        fclose($stream);
    }

    public function test_response_overrides_reach_every_part(): void
    {
        $object = str_repeat('x', 25);

        $handler = new MockHandler();
        for ($i = 0; $i < 3; $i++) {
            $handler->append($this->partResponder($object, 'e'));
        }

        $stream = $this->store($handler)->stream('a.pdf', GetObjectOptions::forceDownload('a.pdf'));
        fclose($stream);

        self::assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            self::assertNotNull($request['disposition']);
        }
    }

    /**
     * One failed part costs one part, not the object: the SDK retries the part request and the
     * download carries on, instead of starting a 357 MB transfer again from byte 0.
     */
    public function test_a_failed_part_is_retried_on_its_own(): void
    {
        $object = str_repeat('ab', 15); // 30 bytes, 3 parts

        $handler = new MockHandler();
        $handler->append($this->partResponder($object, 'e'));
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new S3Exception('cURL error 28: Operation timed out', $cmd, ['connection_error' => true]);
        });
        $handler->append($this->partResponder($object, 'e'));
        $handler->append($this->partResponder($object, 'e'));

        $stream = $this->store($handler, concurrency: 1, retries: 3)->stream('backups/base.tar');

        self::assertSame($object, stream_get_contents($stream));
        self::assertSame(
            ['bytes=0-9', 'bytes=10-19', 'bytes=10-19', 'bytes=20-29'],
            array_column($this->requests, 'range'),
        );
        fclose($stream);
    }

    public function test_an_object_replaced_mid_read_fails_instead_of_mixing_versions(): void
    {
        $object = str_repeat('z', 20);

        $handler = new MockHandler();
        $handler->append($this->partResponder($object, 'etag-v1'));
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new S3Exception('At least one of the pre-conditions you specified did not hold', $cmd, [
                'code' => 'PreconditionFailed',
                'response' => new Response(412),
            ]);
        });

        $this->expectException(ObjectStoreException::class);
        $this->expectExceptionMessage('changed while it was being read');

        $this->store($handler)->stream('backups/base.tar');
    }

    public function test_a_short_part_is_an_error_not_a_truncated_object(): void
    {
        $handler = new MockHandler();
        $handler->append($this->partResponder(str_repeat('q', 20), 'e'));
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result(['Body' => Utils::streamFor('qqq'), 'ContentRange' => 'bytes 10-19/20', 'ETag' => 'e']);
        });

        $this->expectException(ObjectStoreException::class);
        $this->expectExceptionMessage('received 3 bytes for the part at offset 10');

        $this->store($handler)->stream('backups/base.tar');
    }

    public function test_an_empty_object_falls_back_to_a_plain_get(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new S3Exception('The requested range is not satisfiable', $cmd, [
                'code' => 'InvalidRange',
                'response' => new Response(416),
            ]);
        });
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result(['Body' => Utils::streamFor('')]);
        });

        $stream = $this->store($handler)->stream('empty');

        self::assertSame('', stream_get_contents($stream));
        self::assertSame(['bytes=0-9', null], array_column($this->requests, 'range'));
    }

    public function test_a_server_that_ignores_range_is_read_as_the_whole_object(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result(['Body' => Utils::streamFor(str_repeat('w', 25))]);
        });

        $stream = $this->store($handler)->stream('whole');

        self::assertSame(str_repeat('w', 25), stream_get_contents($stream));
        self::assertCount(1, $this->requests);
    }

    public function test_a_content_encoded_object_is_read_whole_because_ranges_would_not_concatenate(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result([
                'Body' => Utils::streamFor('part'),
                'ContentRange' => 'bytes 0-9/40',
                'ContentEncoding' => 'gzip',
            ]);
        });
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result(['Body' => Utils::streamFor('decoded whole object')]);
        });

        $stream = $this->store($handler)->stream('encoded');

        self::assertSame('decoded whole object', stream_get_contents($stream));
        self::assertSame(['bytes=0-9', null], array_column($this->requests, 'range'));
    }

    public function test_a_caller_chosen_range_is_one_direct_request(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->record($cmd);

            return new Result(['Body' => Utils::streamFor('mid')]);
        });

        $stream = $this->store($handler)->stream('a', new GetObjectOptions(new ByteRange(100, 102)));

        self::assertSame('mid', stream_get_contents($stream));
        self::assertSame(['bytes=100-102'], array_column($this->requests, 'range'));
    }

    public function test_a_missing_object_still_maps_to_not_found(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            return new S3Exception('missing', $cmd, ['code' => 'NoSuchKey', 'response' => new Response(404)]);
        });

        $this->expectException(ObjectNotFoundException::class);

        $this->store($handler)->stream('gone');
    }

    private function store(MockHandler $handler, int $concurrency = 4, int $retries = 0): S3CompatibleObjectStore
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => $retries === 0 ? 0 : ['mode' => 'standard', 'max_attempts' => $retries],
        ]);

        return new S3CompatibleObjectStore($client, 'backups', 'r2', 5_242_880, self::PART, $concurrency);
    }

    /** Serves whatever range the command asks for out of $object, like a real S3 endpoint. */
    private function partResponder(string $object, string $etag): \Closure
    {
        return function (CommandInterface $cmd) use ($object, $etag) {
            $this->record($cmd);

            preg_match('/^bytes=(\d+)-(\d+)$/', (string) $cmd['Range'], $m);
            $first = (int) $m[1];
            $last = min((int) $m[2], \strlen($object) - 1);

            return new Result([
                'Body' => Utils::streamFor(substr($object, $first, $last - $first + 1)),
                'ContentRange' => sprintf('bytes %d-%d/%d', $first, $last, \strlen($object)),
                'ETag' => $etag,
            ]);
        };
    }

    private function record(CommandInterface $cmd): void
    {
        $this->requests[] = [
            'range' => $cmd['Range'] ?? null,
            'ifMatch' => $cmd['IfMatch'] ?? null,
            'disposition' => $cmd['ResponseContentDisposition'] ?? null,
        ];
    }
}
