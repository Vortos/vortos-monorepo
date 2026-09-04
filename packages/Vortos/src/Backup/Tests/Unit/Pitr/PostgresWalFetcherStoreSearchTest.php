<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Pitr\ArchivedWalNotFoundException;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * The multi-store search, and what a store that cannot ANSWER means.
 *
 * The configured store list is a superset by design: it includes the primary bucket so a recovery
 * spanning the WAL split can still find pre-split segments. The identity doing the fetching does not
 * necessarily hold rights to all of it — on production the backup node reads its own WAL and backup
 * buckets and is deliberately denied the application's, which is correct least privilege.
 *
 * The search used to be fatal on the first store it could not query, and that failed two production
 * point-in-time drills which had already replayed the entire archive: the WAL bucket answered a
 * missing segment cleanly, the search fell through to the application bucket, and its 403 escaped —
 * so ArchivedWalNotFoundException was never raised and nothing ever learned the log had ended.
 */
final class PostgresWalFetcherStoreSearchTest extends TestCase
{
    private const SEGMENT = '00000001000000DB00000033';
    private const KEY = 'backups/production/postgres/wal/00000001000000DB00000033';

    /**
     * @param array<string, bool> $stores name => whether this identity may read it at all
     */
    private function fetcher(array $stores): PostgresWalFetcher
    {
        $factories = [];

        foreach ($stores as $name => $readable) {
            $inner = new InMemoryObjectStore();
            $inner->denyAll = !$readable;
            $factories[$name] = static fn (): ObjectStoreBackupStore => new ObjectStoreBackupStore($inner);
        }

        return new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator($factories)),
            array_keys($factories),
            'backups',
        );
    }

    /**
     * The production shape: the WAL bucket says no cleanly, the application bucket denies. The
     * honest answer is "not in the archive", because the one store that could answer said so.
     */
    public function testAStoreThatCannotAnswerDoesNotEndTheSearch(): void
    {
        $path = sys_get_temp_dir() . '/wal-search-' . bin2hex(random_bytes(4));

        $this->expectException(ArchivedWalNotFoundException::class);

        try {
            $this->fetcher(['wal' => true, 'app' => false])->fetch(self::SEGMENT, $path, 'production');
        } finally {
            @unlink($path);
        }
    }

    /**
     * But if NOTHING could answer, that is an outage and must surface as one. Reported as a miss it
     * would tell a recovery the log ends here and produce a database that looks successfully
     * restored to the wrong instant.
     */
    public function testEveryStoreFailingIsAnErrorAndNeverAMiss(): void
    {
        $path = sys_get_temp_dir() . '/wal-search-' . bin2hex(random_bytes(4));

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/every configured store failed/');

        try {
            $this->fetcher(['wal' => false, 'app' => false])->fetch(self::SEGMENT, $path, 'production');
        } finally {
            @unlink($path);
        }
    }
}
