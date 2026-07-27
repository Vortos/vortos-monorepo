<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No production code may choose a backup to act on via the unfiltered latest() lookup.
 *
 * Introducing continuous WAL archiving silently changed the meaning of every "latest artifact"
 * query in the system. A wal_segment now lands roughly every sixty seconds, so "the newest
 * artifact" is essentially always one — and a WAL segment cannot be restored on its own; it replays
 * onto a base backup.
 *
 * Four separate call sites were quietly broken by that one change, and none of them failed loudly:
 *
 *   - BackupFreshnessInspector reported backups fresh forever, disarming the only alarm that
 *     catches a silently-dead backup worker.
 *   - DrillRunner would have rehearsed restoring a WAL fragment, so the weekly proof-of-
 *     recoverability could pass having proved nothing.
 *   - BackupFreshnessCollector kept the dashboard gauge permanently green.
 *   - BackupRestoreCommand, run without an explicit id, would hand an operator a WAL fragment
 *     during an actual incident.
 *
 * The lesson is not "remember to filter". It is that a new artifact KIND changes the meaning of
 * existing queries, and a human re-auditing every call site is not a control. This test is.
 *
 * latest() itself remains legitimate for questions genuinely about the newest row of any kind;
 * such a caller should be added to the allowlist below with a reason.
 */
final class NoUnfilteredLatestArtifactTest extends TestCase
{
    /**
     * Files permitted to call the unfiltered lookup, each with the reason it is correct there.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // The implementation of the method itself.
        'Catalog/DbalBackupCatalogReadModel.php' => 'defines latest() and latestOfKind()',
    ];

    public function test_no_production_code_selects_a_backup_with_the_unfiltered_lookup(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($root . '/', '', $file->getPathname());

            if (str_starts_with($relative, 'Tests/') || isset(self::ALLOWED[$relative])) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // Matches ->latest( but not ->latestOfKind(
            if (preg_match('/->latest\(\s*\$/', $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These call the unfiltered latest() on the backup catalog. With WAL archiving enabled "
            . "that returns a wal_segment, which cannot be restored on its own. Use latestOfKind() "
            . "with the restorable kinds, or add an allowlist entry explaining why any-kind is "
            . "correct here.\n  " . implode("\n  ", $offenders),
        );
    }
}
