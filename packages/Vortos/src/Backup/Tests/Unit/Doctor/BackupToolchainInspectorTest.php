<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Doctor\BackupToolchainInspector;

final class BackupToolchainInspectorTest extends TestCase
{
    /** A probe backed by a fixed map of binary → [path, major]; absent binaries return null. */
    private function probe(array $map): \Closure
    {
        return static fn (string $binary): ?array => $map[$binary] ?? null;
    }

    public function test_postgres_all_present_and_new_enough_is_satisfied(): void
    {
        $inspector = new BackupToolchainInspector($this->probe([
            'pg_dump' => ['path' => '/usr/bin/pg_dump', 'major' => 18],
            'pg_restore' => ['path' => '/usr/bin/pg_restore', 'major' => 18],
            'pg_basebackup' => ['path' => '/usr/bin/pg_basebackup', 'major' => 18],
        ]));

        $report = $inspector->inspect(DatabaseEngine::Postgres, 18);

        $this->assertTrue($report->isSatisfied());
        $this->assertSame([], $report->failures());
        $this->assertCount(3, $report->findings);
    }

    public function test_missing_pg_dump_is_a_failure(): void
    {
        $inspector = new BackupToolchainInspector($this->probe([
            'pg_restore' => ['path' => '/usr/bin/pg_restore', 'major' => 18],
            'pg_basebackup' => ['path' => '/usr/bin/pg_basebackup', 'major' => 18],
        ]));

        $report = $inspector->inspect(DatabaseEngine::Postgres, 18);

        $this->assertFalse($report->isSatisfied());
        $failures = $report->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('pg_dump', $failures[0]->name);
        $this->assertFalse($failures[0]->present);
    }

    public function test_pg_dump_older_than_server_is_a_failure(): void
    {
        $inspector = new BackupToolchainInspector($this->probe([
            'pg_dump' => ['path' => '/usr/bin/pg_dump', 'major' => 16],
            'pg_restore' => ['path' => '/usr/bin/pg_restore', 'major' => 16],
            'pg_basebackup' => ['path' => '/usr/bin/pg_basebackup', 'major' => 16],
        ]));

        $report = $inspector->inspect(DatabaseEngine::Postgres, 18);

        $this->assertFalse($report->isSatisfied());
        $this->assertCount(3, $report->failures());
        foreach ($report->failures() as $finding) {
            $this->assertTrue($finding->present);
            $this->assertFalse($finding->versionSatisfied);
        }
    }

    public function test_present_but_undetectable_version_fails_only_when_gated(): void
    {
        $probe = $this->probe([
            'pg_dump' => ['path' => '/usr/bin/pg_dump', 'major' => null],
            'pg_restore' => ['path' => '/usr/bin/pg_restore', 'major' => null],
            'pg_basebackup' => ['path' => '/usr/bin/pg_basebackup', 'major' => null],
        ]);

        // Server major known → cannot prove compatibility → fail.
        $this->assertFalse((new BackupToolchainInspector($probe))->inspect(DatabaseEngine::Postgres, 18)->isSatisfied());
        // Server major unknown → presence-only → satisfied.
        $this->assertTrue((new BackupToolchainInspector($probe))->inspect(DatabaseEngine::Postgres, null)->isSatisfied());
    }

    public function test_mongo_is_presence_checked_only_not_version_gated(): void
    {
        $inspector = new BackupToolchainInspector($this->probe([
            // A deliberately "low" number: mongo tools use an independent 100.x scheme, never gated.
            'mongodump' => ['path' => '/usr/bin/mongodump', 'major' => 100],
            'mongorestore' => ['path' => '/usr/bin/mongorestore', 'major' => 100],
        ]));

        $report = $inspector->inspect(DatabaseEngine::Mongo, 7);

        $this->assertTrue($report->isSatisfied());
        $this->assertCount(2, $report->findings);
    }

    public function test_missing_mongorestore_is_a_failure(): void
    {
        $inspector = new BackupToolchainInspector($this->probe([
            'mongodump' => ['path' => '/usr/bin/mongodump', 'major' => 100],
        ]));

        $report = $inspector->inspect(DatabaseEngine::Mongo);

        $this->assertFalse($report->isSatisfied());
        $this->assertSame('mongorestore', $report->failures()[0]->name);
    }
}
