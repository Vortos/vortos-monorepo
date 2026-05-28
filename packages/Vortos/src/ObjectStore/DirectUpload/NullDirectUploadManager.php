<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DirectUpload;

use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class NullDirectUploadManager implements DirectUploadManagerInterface
{
    public function __construct(
        private readonly ObjectStoreInterface $objectStore,
        private readonly string $temporaryPrefix = 'tmp',
        private readonly ?ObjectPromotionPolicyInterface $promotionPolicy = null,
    ) {}

    public function createUploadIntent(ObjectKey|string $temporaryKey, TemporaryUploadUrlOptions $options): DirectUploadIntent
    {
        $key = ObjectKey::from($temporaryKey);

        return new DirectUploadIntent(
            $key,
            $this->objectStore->temporaryUploadUrl($key, $options),
            $this->temporaryPrefix,
        );
    }

    public function promote(PromoteObjectRequest $request): PromotionResult
    {
        $this->promotionPolicy?->assertCanPromote($request);

        return new PromotionResult(
            $request->temporaryKey(),
            $request->permanentKey(),
            new StoredObject($request->permanentKey(), null, 0),
            false,
        );
    }

    public function abort(ObjectKey|string $temporaryKey): DeleteResult
    {
        return $this->objectStore->delete($temporaryKey);
    }
}
