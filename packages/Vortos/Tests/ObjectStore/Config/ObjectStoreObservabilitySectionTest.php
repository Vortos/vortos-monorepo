<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Config;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;

final class ObjectStoreObservabilitySectionTest extends TestCase
{
    public function test_maps_typed_presign_operation_to_presign_section(): void
    {
        $this->assertSame(
            ObjectStoreObservabilitySection::Presign,
            ObjectStoreObservabilitySection::fromOperation(ObjectStoreOperationName::TemporaryUploadUrl),
        );
    }

    public function test_maps_typed_outbox_operation_to_outbox_section(): void
    {
        $this->assertSame(
            ObjectStoreObservabilitySection::Outbox,
            ObjectStoreObservabilitySection::fromOperation(ObjectStoreOperationName::OutboxRelay),
        );
    }

    public function test_maps_typed_lifecycle_operation_to_lifecycle_section(): void
    {
        $this->assertSame(
            ObjectStoreObservabilitySection::Lifecycle,
            ObjectStoreObservabilitySection::fromOperation(ObjectStoreOperationName::LifecycleApply),
        );
    }
}
