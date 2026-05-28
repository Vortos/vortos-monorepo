<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver\Log;

use Psr\Log\LoggerInterface;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\StoredObject;

final class LogObjectStore extends NullObjectStore
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $bucketName = '',
    ) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);

        $this->logger->info('object_store.put', [
            'bucket' => $this->bucketName,
            'key' => $key->value(),
            'size' => $body->size(),
            'driver' => 'log',
        ]);

        return new StoredObject($key, 'log-' . sha1($key->value()), $body->size() ?? 0);
    }
}
