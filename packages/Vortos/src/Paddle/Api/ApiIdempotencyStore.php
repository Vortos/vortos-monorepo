<?php

declare(strict_types=1);

namespace Vortos\Paddle\Api;

use Doctrine\DBAL\Connection;

final class ApiIdempotencyStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $tableName,
        private readonly int        $ttlSeconds,
    ) {}

    public function generateKey(string $operation): string
    {
        $key = $this->createUuid();

        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (key_id, operation, created_at, expires_at) VALUES (:key_id, :operation, :created_at, :expires_at)',
                $this->tableName,
            ),
            [
                'key_id'     => $key,
                'operation'  => $operation,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ],
        );

        return $key;
    }

    public function pruneExpired(): int
    {
        return (int) $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE expires_at <= :now',
                $this->tableName,
            ),
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    private function createUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
