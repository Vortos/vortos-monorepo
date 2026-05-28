<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Suppression;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Suppression\DbalSuppressionList;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class DbalSuppressionListTest extends TestCase
{
    private const TABLE = 'aws_ses_suppression_list';

    private function makeList(Connection $conn): DbalSuppressionList
    {
        return new DbalSuppressionList($conn, self::TABLE);
    }

    public function test_is_suppressed_returns_true_when_record_exists(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('1');

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains(self::TABLE), ['email' => 'user@example.com'])
            ->willReturn($result);

        $list = $this->makeList($conn);
        $this->assertTrue($list->isSuppressed(new EmailAddress('user@example.com')));
    }

    public function test_is_suppressed_returns_false_when_no_record(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $list = $this->makeList($conn);
        $this->assertFalse($list->isSuppressed(new EmailAddress('clean@example.com')));
    }

    public function test_is_suppressed_normalises_to_lowercase(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->anything(), ['email' => 'user@example.com'])
            ->willReturn($result);

        $list = $this->makeList($conn);
        $list->isSuppressed(new EmailAddress('USER@EXAMPLE.COM'));
    }

    public function test_suppress_executes_upsert(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO ' . self::TABLE),
                $this->arrayHasKey('email'),
            );

        $list = $this->makeList($conn);
        $list->suppress(new EmailAddress('bounce@example.com'), SuppressionReason::Bounce);
    }

    public function test_suppress_stores_reason_value(): void
    {
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 1;
            });

        $list = $this->makeList($conn);
        $list->suppress(new EmailAddress('bounce@example.com'), SuppressionReason::Bounce);

        $this->assertSame('bounce', $capturedParams['reason']);
    }

    public function test_suppress_lowercases_email(): void
    {
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 1;
            });

        $list = $this->makeList($conn);
        $list->suppress(new EmailAddress('Bounce@Example.COM'), SuppressionReason::Bounce);

        $this->assertSame('bounce@example.com', $capturedParams['email']);
    }

    public function test_unsuppress_executes_delete(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM ' . self::TABLE),
                ['email' => 'user@example.com'],
            );

        $list = $this->makeList($conn);
        $list->unsuppress(new EmailAddress('user@example.com'));
    }

    public function test_list_fetches_with_limit_and_offset(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('LIMIT'),
                $this->arrayHasKey('limit'),
            )
            ->willReturn($result);

        $list = $this->makeList($conn);
        $entries = $list->list(25, 50);

        $this->assertSame([], $entries);
    }

    public function test_list_hydrates_rows_to_suppression_entries(): void
    {
        $rows = [[
            'id'           => '018e-uuid',
            'email_address' => 'test@example.com',
            'reason'       => 'complaint',
            'suppressed_at' => '2024-01-01 00:00:00',
            'created_at'   => '2024-01-01 00:00:00',
        ]];

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $list    = $this->makeList($conn);
        $entries = $list->list();

        $this->assertCount(1, $entries);
        $this->assertSame('test@example.com', $entries[0]->address()->address());
        $this->assertSame(SuppressionReason::Complaint, $entries[0]->reason());
    }
}
