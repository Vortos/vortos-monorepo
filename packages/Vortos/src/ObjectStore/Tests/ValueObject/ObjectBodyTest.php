<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\ObjectBody;

final class ObjectBodyTest extends TestCase
{
    public function test_string_body_reports_size(): void
    {
        $body = ObjectBody::from('abc');

        $this->assertFalse($body->isStream());
        $this->assertSame(3, $body->size());
        $this->assertSame('abc', $body->contents());
    }

    public function test_stream_body_can_be_read(): void
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, 'video');
        rewind($stream);

        $body = ObjectBody::from($stream, 5);

        $this->assertTrue($body->isStream());
        $this->assertSame(5, $body->size());
        $this->assertSame('video', $body->contents());
    }
}
