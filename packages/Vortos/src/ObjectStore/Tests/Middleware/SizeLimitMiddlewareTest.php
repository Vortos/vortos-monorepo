<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Exception\ObjectTooLargeException;
use Vortos\ObjectStore\Middleware\SizeLimitMiddleware;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;

final class SizeLimitMiddlewareTest extends TestCase
{
    public function test_rejects_uploads_above_configured_maximum(): void
    {
        $middleware = new SizeLimitMiddleware(2);
        $operation = new ObjectStoreOperation('put', [
            'key' => new ObjectKey('tmp/video.mp4'),
            'body' => ObjectBody::from('abc'),
        ]);

        $this->expectException(ObjectTooLargeException::class);
        $middleware->process($operation, static fn() => null);
    }

    public function test_allows_unknown_stream_size(): void
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, 'abc');
        rewind($stream);

        $middleware = new SizeLimitMiddleware(2);
        $operation = new ObjectStoreOperation('put', [
            'key' => new ObjectKey('tmp/video.mp4'),
            'body' => ObjectBody::from($stream),
        ]);

        $this->assertSame('ok', $middleware->process($operation, static fn() => 'ok'));
    }
}
