<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\DirectUpload;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\DirectUpload\StandaloneDirectUploadManager;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;

final class StandaloneDirectUploadManagerTest extends TestCase
{
    public function test_promote_opens_own_transaction_when_none_is_active(): void
    {
        $request = PromoteObjectRequest::fromKeys('tmp/a.mp4', 'registrations/a.mp4');
        $result = new PromotionResult($request->temporaryKey(), $request->permanentKey(), new StoredObject(new ObjectKey('registrations/a.mp4'), null, 0), false);

        $inner = $this->createMock(DirectUploadManagerInterface::class);
        $inner->expects($this->once())->method('promote')->with($request)->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());

        $this->assertSame($result, (new StandaloneDirectUploadManager($connection, $inner))->promote($request));
    }
}
