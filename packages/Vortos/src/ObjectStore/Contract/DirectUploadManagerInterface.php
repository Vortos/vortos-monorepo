<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

/**
 * Primary direct-upload lifecycle contract.
 *
 * createUploadIntent() returns a direct-to-cloud presign for a temporary key.
 * With outbox enabled, promote() and abort() are transactional outbox writes and
 * require an active CommandBus/UnitOfWork transaction. Use the standalone or
 * immediate direct-upload interfaces when that guarantee is not desired.
 */
interface DirectUploadManagerInterface
{
    public function createUploadIntent(
        ObjectKey|string $temporaryKey,
        TemporaryUploadUrlOptions $options,
    ): DirectUploadIntent;

    public function promote(PromoteObjectRequest $request): PromotionResult;

    public function abort(ObjectKey|string $temporaryKey): DeleteResult;
}
