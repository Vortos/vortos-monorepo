<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DirectUpload;

use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ImmediateDirectUploadManagerInterface;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class ImmediateDirectUploadManager implements ImmediateDirectUploadManagerInterface
{
    public function __construct(private readonly DirectUploadManagerInterface $inner) {}

    public function createUploadIntent(ObjectKey|string $temporaryKey, TemporaryUploadUrlOptions $options): DirectUploadIntent
    {
        return $this->inner->createUploadIntent($temporaryKey, $options);
    }

    public function promote(PromoteObjectRequest $request): PromotionResult
    {
        return $this->inner->promote($request);
    }

    public function abort(ObjectKey|string $temporaryKey): DeleteResult
    {
        return $this->inner->abort($temporaryKey);
    }
}
