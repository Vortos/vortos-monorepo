<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Config;

use Vortos\ObjectStore\Contract\ObjectStoreOperationName;

enum ObjectStoreObservabilitySection: string
{
    case Driver = 'driver';
    case Presign = 'presign';
    case DirectUpload = 'direct_upload';
    case Outbox = 'outbox';
    case Multipart = 'multipart';
    case Lifecycle = 'lifecycle';

    public static function fromOperation(ObjectStoreOperationName|string $operation): self
    {
        $operation = $operation instanceof ObjectStoreOperationName ? $operation->value : $operation;

        return match ($operation) {
            ObjectStoreOperationName::TemporaryDownloadUrl->value,
            ObjectStoreOperationName::TemporaryUploadUrl->value,
            ObjectStoreOperationName::TemporaryPostUpload->value => self::Presign,
            ObjectStoreOperationName::Promote->value,
            ObjectStoreOperationName::Abort->value,
            ObjectStoreOperationName::CreateUploadIntent->value => self::DirectUpload,
            ObjectStoreOperationName::OutboxRelay->value,
            ObjectStoreOperationName::OutboxWrite->value => self::Outbox,
            ObjectStoreOperationName::MultipartUpload->value => self::Multipart,
            ObjectStoreOperationName::LifecyclePlan->value,
            ObjectStoreOperationName::LifecycleApply->value,
            ObjectStoreOperationName::LifecycleRemove->value => self::Lifecycle,
            default => self::Driver,
        };
    }
}
