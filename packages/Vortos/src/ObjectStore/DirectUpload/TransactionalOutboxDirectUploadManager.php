<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DirectUpload;

use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectOutboxWriterInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class TransactionalOutboxDirectUploadManager implements DirectUploadManagerInterface
{
    public function __construct(
        private readonly DirectUploadManagerInterface $inner,
        private readonly ObjectOutboxWriterInterface $writer,
    ) {}

    public function createUploadIntent(ObjectKey|string $temporaryKey, TemporaryUploadUrlOptions $options): DirectUploadIntent
    {
        return $this->inner->createUploadIntent($temporaryKey, $options);
    }

    public function promote(PromoteObjectRequest $request): PromotionResult
    {
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Promote, ['request' => $request]));

        return new PromotionResult(
            $request->temporaryKey(),
            $request->permanentKey(),
            new StoredObject($request->permanentKey(), null, 0),
            false,
        );
    }

    public function abort(ObjectKey|string $temporaryKey): DeleteResult
    {
        $key = ObjectKey::from($temporaryKey);
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Delete, ['key' => $key]));

        return new DeleteResult($key, false);
    }
}
