<?php

declare(strict_types=1);

namespace Vortos\Tests\Auth\ApiKey\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Vortos\Auth\ApiKey\ApiKeyRecord;
use Vortos\Auth\ApiKey\Storage\DatabaseApiKeyStorage;

final class DatabaseApiKeyStorageTest extends TestCase
{
    private const TABLE = 'vortos_api_keys';

    private function makeStorage(Connection $conn): DatabaseApiKeyStorage
    {
        return new DatabaseApiKeyStorage($conn, self::TABLE);
    }

    public function test_find_by_hash_queries_injected_table(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->with($this->stringContains(self::TABLE), $this->anything())
            ->willReturn(false);

        $this->assertNull($this->makeStorage($conn)->findByHash('abc123'));
    }

    public function test_find_by_hash_returns_null_when_not_found(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $this->assertNull($this->makeStorage($conn)->findByHash('nope'));
    }

    public function test_find_by_hash_hydrates_record_when_found(): void
    {
        $row = $this->validRow();

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $record = $this->makeStorage($conn)->findByHash($row['hashed_key']);

        $this->assertInstanceOf(ApiKeyRecord::class, $record);
        $this->assertSame($row['id'], $record->id);
        $this->assertSame($row['user_id'], $record->userId);
        $this->assertSame($row['name'], $record->name);
        $this->assertSame($row['hashed_key'], $record->hashedKey);
        $this->assertTrue($record->active);
    }

    public function test_save_executes_upsert_on_injected_table(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO ' . self::TABLE));

        $this->makeStorage($conn)->save($this->makeRecord());
    }

    public function test_revoke_updates_active_flag_on_injected_table(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE ' . self::TABLE),
                $this->anything(),
            );

        $this->makeStorage($conn)->revoke('key-id-1');
    }

    public function test_find_by_user_id_queries_injected_table(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains(self::TABLE))
            ->willReturn([]);

        $this->assertSame([], $this->makeStorage($conn)->findByUserId('user-1'));
    }

    public function test_find_by_user_id_hydrates_multiple_records(): void
    {
        $rows = [$this->validRow(), array_merge($this->validRow(), ['id' => 'key-2', 'name' => 'Second Key'])];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $records = $this->makeStorage($conn)->findByUserId('user-1');

        $this->assertCount(2, $records);
        $this->assertContainsOnlyInstancesOf(ApiKeyRecord::class, $records);
    }

    public function test_scopes_are_decoded_from_json(): void
    {
        $row = array_merge($this->validRow(), ['scopes' => '["read","write"]']);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $record = $this->makeStorage($conn)->findByHash('hash');

        $this->assertSame(['read', 'write'], $record->scopes);
    }

    private function validRow(): array
    {
        return [
            'id'           => 'key-1',
            'user_id'      => 'user-1',
            'name'         => 'My Key',
            'hashed_key'   => 'hash-abc',
            'scopes'       => '[]',
            'active'       => true,
            'created_at'   => '2024-01-01T00:00:00+00:00',
            'expires_at'   => null,
            'last_used_at' => null,
        ];
    }

    private function makeRecord(): ApiKeyRecord
    {
        return new ApiKeyRecord(
            id:          'key-1',
            userId:      'user-1',
            name:        'My Key',
            hashedKey:   'hash-abc',
            scopes:      [],
            active:      true,
            createdAt:   new \DateTimeImmutable('2024-01-01'),
            expiresAt:   null,
            lastUsedAt:  null,
        );
    }
}
