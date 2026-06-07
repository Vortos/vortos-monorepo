<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;

final class ObjectStoreOperationTest extends TestCase
{
    public function test_accepts_typed_operation_names(): void
    {
        $operation = new ObjectStoreOperation(ObjectStoreOperationName::TemporaryUploadUrl);

        $this->assertSame('temporary_upload_url', $operation->name());
        $this->assertSame(ObjectStoreOperationName::TemporaryUploadUrl, $operation->typedName());
    }

    public function test_keeps_unknown_operation_names_for_extension_points(): void
    {
        $operation = new ObjectStoreOperation('tenant_custom_operation');

        $this->assertSame('tenant_custom_operation', $operation->name());
        $this->assertNull($operation->typedName());
    }
}
