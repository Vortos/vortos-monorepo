<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\DirectUpload;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\DirectUpload\ImmediateDirectUploadManager;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;

final class ImmediateDirectUploadManagerTest extends TestCase
{
    public function test_delegates_directly_to_inner_manager(): void
    {
        $request = PromoteObjectRequest::fromKeys('tmp/a.mp4', 'registrations/a.mp4');
        $result = new PromotionResult($request->temporaryKey(), $request->permanentKey(), new StoredObject(new ObjectKey('registrations/a.mp4'), null, 0), false);
        $inner = $this->createMock(DirectUploadManagerInterface::class);
        $inner->expects($this->once())->method('promote')->with($request)->willReturn($result);

        $this->assertSame($result, (new ImmediateDirectUploadManager($inner))->promote($request));
    }
}
