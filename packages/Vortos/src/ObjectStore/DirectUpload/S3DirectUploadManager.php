<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DirectUpload;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\DirectUploadIntent;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class S3DirectUploadManager implements DirectUploadManagerInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
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

        try {
            $result = $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $request->permanentKey()->value(),
                'CopySource' => rawurlencode($this->bucket . '/' . $request->temporaryKey()->value()),
            ]);

            $deleted = false;
            if ($request->deleteTemporarySource()) {
                $this->client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $request->temporaryKey()->value(),
                ]);
                $deleted = true;
            }
        } catch (AwsException $e) {
            throw new ObjectStoreException(
                sprintf('Failed to promote object %s to %s: %s', $request->temporaryKey(), $request->permanentKey(), $e->getAwsErrorMessage() ?? $e->getMessage()),
                previous: $e,
            );
        }

        return new PromotionResult(
            $request->temporaryKey(),
            $request->permanentKey(),
            new StoredObject(
                $request->permanentKey(),
                $this->normalizeEtag($result['CopyObjectResult']['ETag'] ?? $result['ETag'] ?? null),
                0,
                isset($result['VersionId']) ? (string) $result['VersionId'] : null,
            ),
            $deleted,
        );
    }

    public function abort(ObjectKey|string $temporaryKey): DeleteResult
    {
        $key = ObjectKey::from($temporaryKey);
        $prefix = trim($this->temporaryPrefix, '/');
        if ($prefix === '' || !str_starts_with($key->value(), $prefix . '/')) {
            throw new \InvalidArgumentException(sprintf('Abort key must be under the "%s/" temporary prefix.', $prefix));
        }

        return $this->objectStore->delete($key);
    }

    private function normalizeEtag(mixed $etag): ?string
    {
        return $etag === null ? null : trim((string) $etag, '"');
    }
}
