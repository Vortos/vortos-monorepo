<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;

final class PromoteObjectRequestTest extends TestCase
{
    public function test_promotes_from_tmp_to_permanent_key(): void
    {
        $request = PromoteObjectRequest::fromKeys(
            'tmp/upload-id/video.mp4',
            'registrations/registration-id/video.mp4',
        );

        $this->assertSame('tmp/upload-id/video.mp4', $request->temporaryKey()->value());
        $this->assertSame('registrations/registration-id/video.mp4', $request->permanentKey()->value());
        $this->assertTrue($request->deleteTemporarySource());
    }

    public function test_rejects_source_outside_temporary_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PromoteObjectRequest::fromKeys('registrations/video.mp4', 'final/video.mp4');
    }

    public function test_rejects_permanent_key_inside_temporary_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PromoteObjectRequest::fromKeys('tmp/video.mp4', 'tmp/final-video.mp4');
    }
}
