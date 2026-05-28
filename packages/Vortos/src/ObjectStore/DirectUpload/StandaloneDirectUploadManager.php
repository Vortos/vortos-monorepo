<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DirectUpload;

use Doctrine\DBAL\Connection;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\StandaloneDirectUploadManagerInterface;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class StandaloneDirectUploadManager implements StandaloneDirectUploadManagerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DirectUploadManagerInterface $transactionalManager,
    ) {}

    public function createUploadIntent(ObjectKey|string $temporaryKey, TemporaryUploadUrlOptions $options): DirectUploadIntent
    {
        return $this->transactionalManager->createUploadIntent($temporaryKey, $options);
    }

    public function promote(PromoteObjectRequest $request): PromotionResult
    {
        return $this->write(fn(): PromotionResult => $this->transactionalManager->promote($request));
    }

    public function abort(ObjectKey|string $temporaryKey): DeleteResult
    {
        return $this->write(fn(): DeleteResult => $this->transactionalManager->abort($temporaryKey));
    }

    private function write(callable $operation): mixed
    {
        if ($this->connection->isTransactionActive()) {
            return $operation();
        }

        return $this->connection->transactional($operation);
    }
}
