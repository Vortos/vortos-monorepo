<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Multipart;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\ObjectStore\Contract\ServerSideMultipartMaintenanceInterface;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\ValueObject\MultipartUpload;

final class S3ServerSideMultipartMaintenance implements ServerSideMultipartMaintenanceInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
    ) {}

    public function list(?string $prefix = null): array
    {
        $uploads = [];
        $keyMarker = null;
        $uploadIdMarker = null;

        do {
            $args = ['Bucket' => $this->bucket];
            if ($prefix !== null && $prefix !== '') {
                $args['Prefix'] = $prefix;
            }
            if ($keyMarker !== null) {
                $args['KeyMarker'] = $keyMarker;
            }
            if ($uploadIdMarker !== null) {
                $args['UploadIdMarker'] = $uploadIdMarker;
            }

            try {
                $result = $this->client->listMultipartUploads($args);
            } catch (AwsException $e) {
                throw new ObjectStoreException($e->getAwsErrorMessage() ?? $e->getMessage(), previous: $e);
            }

            foreach (($result['Uploads'] ?? []) as $upload) {
                $uploads[] = new MultipartUpload(
                    (string) $upload['Key'],
                    (string) $upload['UploadId'],
                    $this->dateTime($upload['Initiated'] ?? null),
                );
            }

            $keyMarker = isset($result['NextKeyMarker']) ? (string) $result['NextKeyMarker'] : null;
            $uploadIdMarker = isset($result['NextUploadIdMarker']) ? (string) $result['NextUploadIdMarker'] : null;
        } while (($result['IsTruncated'] ?? false) === true);

        return $uploads;
    }

    public function abort(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            throw new ObjectStoreException($e->getAwsErrorMessage() ?? $e->getMessage(), previous: $e);
        }
    }

    public function abortStale(\DateTimeImmutable $olderThan, ?string $prefix = null): int
    {
        $aborted = 0;

        foreach ($this->list($prefix) as $upload) {
            if ($upload->initiatedAt >= $olderThan) {
                continue;
            }

            $this->abort($upload->key, $upload->uploadId);
            ++$aborted;
        }

        return $aborted;
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value);
    }
}
