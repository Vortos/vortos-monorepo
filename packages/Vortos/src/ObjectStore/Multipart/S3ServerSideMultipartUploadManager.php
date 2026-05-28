<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Multipart;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ServerSideMultipartUploadManagerInterface;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\ServerSideMultipartUploadOptions;
use Vortos\ObjectStore\ValueObject\StoredObject;

final class S3ServerSideMultipartUploadManager implements ServerSideMultipartUploadManagerInterface
{
    private const MIN_PART_SIZE = 5_242_880;
    private const MAX_PARTS = 10_000;

    public function __construct(
        private readonly S3Client $client,
        private readonly ObjectStoreInterface $singlePartStore,
        private readonly string $bucket,
        private readonly int $thresholdBytes = 104_857_600,
        private readonly int $partSizeBytes = 16_777_216,
        private readonly bool $abortOnFailure = true,
        private readonly int $maxObjectSizeBytes = 5_497_558_138_880,
        private readonly int $maxInlineBodyBytes = 16_777_216,
        private readonly int $maxAttempts = 3,
        private readonly int $concurrency = 4,
        private readonly int $backoffBaseMilliseconds = 100,
        private readonly int $backoffCapMilliseconds = 2_000,
        private readonly ?string $checksumAlgorithm = null,
    ) {
        if ($partSizeBytes < self::MIN_PART_SIZE) {
            throw new \InvalidArgumentException('S3 server-side multipart part size must be at least 5 MiB.');
        }

        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('S3 server-side multipart max attempts must be at least 1.');
        }

        if ($concurrency < 1) {
            throw new \InvalidArgumentException('S3 server-side multipart concurrency must be at least 1.');
        }
    }

    public function upload(
        ObjectKey|string $key,
        mixed $body,
        ?PutObjectOptions $options = null,
        ?ServerSideMultipartUploadOptions $transferOptions = null,
    ): StoredObject {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);
        $options ??= PutObjectOptions::default();

        $plan = $this->plan($body, $transferOptions);

        if ($body->size() !== null && $body->size() <= $plan['thresholdBytes']) {
            return $this->singlePartStore->put($key, $body, $options);
        }

        $this->assertInlineBodyAllowed($body, $plan['maxInlineBodyBytes']);
        $stream = $this->streamFor($body);
        $uploadId = null;

        try {
            $create = $this->client->createMultipartUpload($this->createRequest($key, $options, $plan['checksumAlgorithm']));
            $uploadId = (string) $create['UploadId'];

            $parts = $this->uploadParts($key, $uploadId, $stream, $plan);

            $completeParts = array_map(
                static fn(array $part): array => ['PartNumber' => $part['PartNumber'], 'ETag' => $part['ETag']],
                $parts,
            );

            $complete = $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $completeParts],
            ]);

            return new StoredObject(
                $key,
                $this->normalizeEtag($complete['ETag'] ?? null),
                $body->size() ?? array_sum(array_column($parts, '_Bytes')),
                isset($complete['VersionId']) ? (string) $complete['VersionId'] : null,
            );
        } catch (\Throwable $e) {
            if ($uploadId !== null && $plan['abortOnFailure']) {
                $this->abort($key, $uploadId);
            }

            if ($e instanceof AwsException) {
                throw new ObjectStoreException($e->getAwsErrorMessage() ?? $e->getMessage(), previous: $e);
            }

            throw $e;
        }
    }

    /** @return array{thresholdBytes:int, partSizeBytes:int, maxInlineBodyBytes:int, maxAttempts:int, concurrency:int, backoffBaseMilliseconds:int, backoffCapMilliseconds:int, checksumAlgorithm:?string, abortOnFailure:bool, onPartUploaded:mixed} */
    private function plan(ObjectBody $body, ?ServerSideMultipartUploadOptions $options): array
    {
        $size = $body->size();
        $maxObjectSize = $options?->maxObjectSizeBytes ?? $this->maxObjectSizeBytes;
        if ($size !== null && $size > $maxObjectSize) {
            throw new ObjectStoreException(sprintf('Object size %d exceeds configured server-side multipart limit %d.', $size, $maxObjectSize));
        }

        $partSize = max(self::MIN_PART_SIZE, $options?->partSizeBytes ?? $this->partSizeBytes);
        if ($size !== null) {
            $partSize = max($partSize, (int) ceil($size / self::MAX_PARTS));
        }

        return [
            'thresholdBytes' => $options?->thresholdBytes ?? $this->thresholdBytes,
            'partSizeBytes' => $partSize,
            'maxInlineBodyBytes' => $options?->maxInlineBodyBytes ?? $this->maxInlineBodyBytes,
            'maxAttempts' => $options?->maxAttempts ?? $this->maxAttempts,
            'concurrency' => max(1, $options?->concurrency ?? $this->concurrency),
            'backoffBaseMilliseconds' => $options?->backoffBaseMilliseconds ?? $this->backoffBaseMilliseconds,
            'backoffCapMilliseconds' => $options?->backoffCapMilliseconds ?? $this->backoffCapMilliseconds,
            'checksumAlgorithm' => $options?->checksumAlgorithm ?? $this->checksumAlgorithm,
            'abortOnFailure' => $this->abortOnFailure,
            'onPartUploaded' => $options?->onPartUploaded,
        ];
    }

    private function createRequest(ObjectKey $key, PutObjectOptions $options, ?string $checksumAlgorithm): array
    {
        $request = [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
        ];

        if ($checksumAlgorithm !== null && $checksumAlgorithm !== '') {
            $request['ChecksumAlgorithm'] = $checksumAlgorithm;
        }

        if ($options->contentType() !== null) {
            $request['ContentType'] = $options->contentType()->value();
        }

        if ($options->metadata() !== []) {
            $request['Metadata'] = $options->metadata();
        }

        if ($options->cacheControl() !== null) {
            $request['CacheControl'] = $options->cacheControl();
        }

        if ($options->contentDisposition() !== null) {
            $request['ContentDisposition'] = $options->contentDisposition();
        }

        return $request;
    }

    /** @param array<string, mixed> $plan @return array<int, array{PartNumber:int, ETag:string, _Bytes:int}> */
    private function uploadParts(ObjectKey $key, string $uploadId, mixed $stream, array $plan): array
    {
        $parts = [];
        $partNumber = 1;

        while (!feof($stream)) {
            $batch = [];

            while (count($batch) < $plan['concurrency'] && !feof($stream)) {
                $chunk = fread($stream, $plan['partSizeBytes']);
                if ($chunk === false) {
                    throw new ObjectStoreException('Failed to read server-side multipart upload stream.');
                }

                if ($chunk === '') {
                    continue;
                }

                if ($partNumber > self::MAX_PARTS) {
                    throw new ObjectStoreException('Server-side multipart upload exceeded the S3 10,000 part limit.');
                }

                $batch[] = [
                    'PartNumber' => $partNumber,
                    'Body' => $chunk,
                    '_Bytes' => strlen($chunk),
                ];

                ++$partNumber;
            }

            if ($batch === []) {
                continue;
            }

            foreach ($this->uploadPartBatch($key, $uploadId, $batch, $plan) as $uploadedPart) {
                $parts[] = $uploadedPart;

                if (is_callable($plan['onPartUploaded'])) {
                    ($plan['onPartUploaded'])($uploadedPart['PartNumber'], $uploadedPart['_Bytes']);
                }
            }
        }

        if ($parts === []) {
            throw new ObjectStoreException('Server-side multipart upload body was empty.');
        }

        return $parts;
    }

    /** @param array<int, array{PartNumber:int, Body:string, _Bytes:int}> $batch @param array<string, mixed> $plan @return array<int, array{PartNumber:int, ETag:string, _Bytes:int}> */
    private function uploadPartBatch(ObjectKey $key, string $uploadId, array $batch, array $plan): array
    {
        $pending = [];
        foreach ($batch as $part) {
            $pending[$part['PartNumber']] = $part;
        }

        $completed = [];

        for ($attempt = 1; $pending !== [] && $attempt <= $plan['maxAttempts']; ++$attempt) {
            $promises = [];
            foreach ($pending as $partNumber => $part) {
                $promises[$partNumber] = $this->client->uploadPartAsync([
                    'Bucket' => $this->bucket,
                    'Key' => $key->value(),
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body' => $part['Body'],
                ]);
            }

            $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
            $retry = [];

            foreach ($settled as $partNumber => $result) {
                $part = $pending[(int) $partNumber];

                if ($result['state'] === 'fulfilled') {
                    $completed[(int) $partNumber] = [
                        'PartNumber' => (int) $partNumber,
                        'ETag' => (string) $result['value']['ETag'],
                        '_Bytes' => $part['_Bytes'],
                    ];
                    continue;
                }

                $reason = $result['reason'];
                if (!$reason instanceof AwsException || $attempt >= $plan['maxAttempts'] || !$this->isRetryable($reason)) {
                    throw $reason instanceof \Throwable ? $reason : new ObjectStoreException('Server-side multipart upload part failed.');
                }

                $retry[(int) $partNumber] = $part;
            }

            $pending = $retry;

            if ($pending !== []) {
                usleep(min($plan['backoffCapMilliseconds'], $plan['backoffBaseMilliseconds'] * (2 ** ($attempt - 1))) * 1000);
            }
        }

        ksort($completed);

        return array_values($completed);
    }

    private function isRetryable(AwsException $e): bool
    {
        try {
            $status = $e->getStatusCode();
        } catch (\Throwable) {
            $status = null;
        }
        $code = (string) $e->getAwsErrorCode();

        return $status === 0
            || $status === null
            || $status >= 500
            || in_array($code, ['RequestTimeout', 'Throttling', 'ThrottlingException', 'SlowDown', 'InternalError'], true);
    }

    private function abort(ObjectKey $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable) {
        }
    }

    /** @return resource */
    private function streamFor(ObjectBody $body): mixed
    {
        if ($body->isStream()) {
            $stream = $body->raw();
            if (is_resource($stream)) {
                return $stream;
            }
        }

        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $body->contents());
        rewind($stream);

        return $stream;
    }

    private function assertInlineBodyAllowed(ObjectBody $body, int $maxInlineBodyBytes): void
    {
        if (!$body->isStream() && $body->size() !== null && $body->size() > $maxInlineBodyBytes) {
            throw new ObjectStoreException(sprintf(
                'Inline server-side multipart body exceeds %d bytes. Use a stream or file-backed source.',
                $maxInlineBodyBytes,
            ));
        }
    }

    private function normalizeEtag(mixed $etag): ?string
    {
        return $etag === null ? null : trim((string) $etag, '"');
    }
}
