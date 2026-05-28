<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\Exception\OutboxSerializationException;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;

final class ObjectOperationSerializer
{
    public function __construct(private readonly int $maxInlinePayloadBytes = 1048576)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(ObjectStoreOperation $operation): array
    {
        return match ($operation->name()) {
            'put' => $this->putToArray($operation),
            'delete' => ['name' => 'delete', 'key' => $this->key($operation, 'key')],
            'copy', 'move' => $this->copyLikeToArray($operation),
            'promote' => $this->promoteToArray($operation),
            default => throw new OutboxSerializationException(sprintf('Unsupported object-store outbox operation: %s', $operation->name())),
        };
    }

    public function fromArray(array $payload): ObjectStoreOperation
    {
        return match ($payload['name'] ?? null) {
            'put' => new ObjectStoreOperation(ObjectStoreOperationName::Put, [
                'key' => new ObjectKey((string) $payload['key']),
                'body' => ObjectBody::from(base64_decode((string) $payload['body_base64'], true) ?: ''),
                'options' => null,
            ]),
            'delete' => new ObjectStoreOperation(ObjectStoreOperationName::Delete, ['key' => new ObjectKey((string) $payload['key'])]),
            'copy', 'move' => new ObjectStoreOperation(ObjectStoreOperationName::from((string) $payload['name']), [
                'source' => new ObjectKey((string) $payload['source']),
                'target' => new ObjectKey((string) $payload['target']),
                'options' => null,
            ]),
            'promote' => new ObjectStoreOperation(ObjectStoreOperationName::Promote, [
                'request' => new PromoteObjectRequest(
                    new ObjectKey((string) $payload['temporary_key']),
                    new ObjectKey((string) $payload['permanent_key']),
                    (string) ($payload['temporary_prefix'] ?? 'tmp'),
                    (bool) ($payload['delete_temporary_source'] ?? true),
                ),
            ]),
            default => throw new OutboxSerializationException('Unsupported object-store outbox payload.'),
        };
    }

    private function putToArray(ObjectStoreOperation $operation): array
    {
        $body = $operation->context()['body'] ?? null;
        if (!$body instanceof ObjectBody) {
            throw new OutboxSerializationException('Put operation requires ObjectBody context.');
        }

        if ($body->isStream()) {
            throw new OutboxSerializationException('Stream bodies cannot be serialized into the object-store outbox. Use direct-to-cloud uploads.');
        }

        $contents = $body->contents();
        if (strlen($contents) > $this->maxInlinePayloadBytes) {
            throw new OutboxSerializationException('Object body exceeds outbox inline payload limit. Use direct-to-cloud uploads.');
        }

        return [
            'name' => 'put',
            'key' => $this->key($operation, 'key'),
            'body_base64' => base64_encode($contents),
        ];
    }

    private function copyLikeToArray(ObjectStoreOperation $operation): array
    {
        return [
            'name' => $operation->name(),
            'source' => $this->key($operation, 'source'),
            'target' => $this->key($operation, 'target'),
        ];
    }

    private function promoteToArray(ObjectStoreOperation $operation): array
    {
        $request = $operation->context()['request'] ?? null;
        if (!$request instanceof PromoteObjectRequest) {
            throw new OutboxSerializationException('Promote operation requires PromoteObjectRequest context.');
        }

        return [
            'name' => 'promote',
            'temporary_key' => $request->temporaryKey()->value(),
            'permanent_key' => $request->permanentKey()->value(),
            'temporary_prefix' => $request->temporaryPrefix(),
            'delete_temporary_source' => $request->deleteTemporarySource(),
        ];
    }

    private function key(ObjectStoreOperation $operation, string $field): string
    {
        $key = $operation->context()[$field] ?? null;

        if (!$key instanceof ObjectKey) {
            throw new OutboxSerializationException(sprintf('Operation %s requires ObjectKey context field "%s".', $operation->name(), $field));
        }

        return $key->value();
    }
}
