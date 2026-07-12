<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Audit\Storage\Dbal\Postgres\PostgresAuditExtrasInstaller;

final class PostgresAuditExtrasInstallerTest extends TestCase
{
    public function test_fts_index_name_is_unqualified_for_a_schema_qualified_table(): void
    {
        // Regression: a schema-qualified table ('vortos.audit_events') must NOT produce a
        // schema-qualified CREATE INDEX name ('vortos.audit_events_fts_gin' → PG syntax error).
        $sql = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturnCallback(function (string $s) use (&$sql): int {
            $sql = $s;
            return 0;
        });

        (new PostgresAuditExtrasInstaller($conn, 'vortos.audit_events'))->installFtsIndex();

        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS audit_events_fts_gin ON vortos.audit_events', (string) $sql);
        self::assertStringNotContainsString('vortos.audit_events_fts_gin', (string) $sql);
    }

    public function test_drop_index_is_schema_qualified(): void
    {
        $sql = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturnCallback(function (string $s) use (&$sql): int {
            $sql = $s;
            return 0;
        });

        (new PostgresAuditExtrasInstaller($conn, 'vortos.audit_events'))->dropFtsIndex();

        self::assertStringContainsString('DROP INDEX IF EXISTS vortos.audit_events_fts_gin', (string) $sql);
    }
}
