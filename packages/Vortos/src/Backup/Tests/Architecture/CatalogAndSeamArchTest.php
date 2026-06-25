<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guards for the catalog's append-only-ness, the Block-17 event-sink
 * decoupling, and the no-plaintext-dump-to-disk guarantee.
 */
final class CatalogAndSeamArchTest extends TestCase
{
    private function src(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    public function test_catalog_repository_never_updates(): void
    {
        $repo = $this->src('Catalog/DbalBackupCatalogRepository.php');

        $this->assertStringContainsString('->insert(', $repo);
        $this->assertStringContainsString('->delete(', $repo, 'DELETE is allowed only for retention.');
        $this->assertStringNotContainsString('->update(', $repo, 'The catalog is append-only: no UPDATE path.');
        $this->assertStringNotContainsString('executeStatement', $repo, 'No ad-hoc SQL mutation in the catalog repo.');
    }

    public function test_runner_depends_only_on_the_event_sink_interface(): void
    {
        $runner = $this->src('Service/BackupRunner.php');

        $this->assertStringContainsString('BackupEventSinkInterface', $runner);
        $this->assertStringNotContainsString('LoggingBackupEventSink', $runner);
        $this->assertStringNotContainsString('CompositeBackupEventSink', $runner);
    }

    public function test_no_dump_is_written_to_a_local_file(): void
    {
        // Dumps must stream (process pipe → store), never spill to a tracked/persistent
        // disk path. No production driver/service code may buffer a dump to a file.
        foreach (['Driver/Postgres', 'Driver/Mongo', 'Driver/ObjectStore', 'Service', 'Pitr'] as $dir) {
            foreach ($this->phpFiles(dirname(__DIR__, 2) . '/' . $dir) as $file) {
                $source = (string) file_get_contents($file);
                $this->assertStringNotContainsString('file_put_contents', $source, basename($file) . ' must not write dump bytes to disk.');
                // stdout of a dump subprocess must be a pipe, never a file descriptor.
                $this->assertStringNotContainsString("1 => ['file'", $source, basename($file) . ' must stream stdout via a pipe, not a file.');
            }
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
