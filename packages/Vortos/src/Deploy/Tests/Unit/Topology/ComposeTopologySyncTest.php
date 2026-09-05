<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Topology\ComposeTopologySync;

/**
 * The file this writes describes how the database is run, so every safety property is asserted
 * rather than assumed.
 */
final class ComposeTopologySyncTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-topology-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function compose(string $kafkaExtra = '', string $appExtra = ''): string
    {
        return <<<YAML
        services:
          write_db:
            image: postgres:18-alpine
          kafka:
            image: apache/kafka:4.3.1{$kafkaExtra}
          app-blue:
            image: app:latest{$appExtra}

        volumes:
          wal_archive:
        YAML;
    }

    /** @return array{0: ComposeTopologySync, 1: string, 2: string} */
    private function sync(string $source, ?string $target, array $stateful = ComposeTopologySync::DEFAULT_STATEFUL_SERVICES): array
    {
        $sourcePath = $this->dir . '/source.yaml';
        $targetPath = $this->dir . '/target.yaml';

        file_put_contents($sourcePath, $source);
        if ($target !== null) {
            file_put_contents($targetPath, $target);
        }

        return [new ComposeTopologySync($sourcePath, $targetPath, $stateful), $sourcePath, $targetPath];
    }

    public function testIdenticalTopologiesAreANoOp(): void
    {
        [$sync, , $target] = $this->sync($this->compose(), $this->compose());
        $before = file_get_contents($target);

        $result = $sync->sync(apply: true);

        self::assertSame('in_sync', $result->status);
        self::assertFalse($result->applied);
        self::assertSame($before, file_get_contents($target), 'an in-sync host file must not be rewritten');
    }

    /** Comments are documentation, not topology — rewording one must not read as drift. */
    public function testCommentAndWhitespaceChangesAreNotDrift(): void
    {
        $commented = "# a thoroughly rewritten explanation\n" . $this->compose() . "\n\n";

        [$sync] = $this->sync($commented, $this->compose());

        self::assertSame('in_sync', $sync->sync(apply: true)->status);
    }

    public function testDryRunIsTheDefaultAndNeverWrites(): void
    {
        [$sync, , $target] = $this->sync($this->compose(kafkaExtra: "\n    environment:\n      KAFKA_LOG_RETENTION_BYTES: 268435456"), $this->compose());
        $before = file_get_contents($target);

        $result = $sync->sync(apply: false);

        self::assertSame('drifted', $result->status);
        self::assertFalse($result->applied);
        self::assertSame($before, file_get_contents($target), 'a dry run must leave the host file byte-identical');
    }

    /**
     * The reason this class exists: a committed change that never reached the box. Here it is the
     * real one — a Kafka byte-retention cap written to stop unbounded growth, which sat in the
     * repository for weeks with no effect.
     */
    public function testItNamesTheServicesThatDiffer(): void
    {
        [$sync] = $this->sync(
            $this->compose(kafkaExtra: "\n    environment:\n      KAFKA_LOG_RETENTION_BYTES: 268435456"),
            $this->compose(),
        );

        $result = $sync->sync(apply: true);

        self::assertSame(['kafka'], $result->changedServices);
        self::assertSame(['kafka'], $result->statefulServices);
        self::assertTrue($result->needsManualConvergence());
    }

    /**
     * A stateless service changing is not something anyone has to act on — the blue/green deploy
     * that runs moments later owns those containers.
     */
    public function testAStatelessChangeNeedsNoManualConvergence(): void
    {
        [$sync] = $this->sync($this->compose(appExtra: "\n    restart: always"), $this->compose());

        $result = $sync->sync(apply: true);

        self::assertSame(['app-blue'], $result->changedServices);
        self::assertSame([], $result->statefulServices);
        self::assertFalse($result->needsManualConvergence());
    }

    public function testItWritesTheDesiredStateAndKeepsARollbackCopy(): void
    {
        $desired = $this->compose(appExtra: "\n    restart: always");
        [$sync, , $target] = $this->sync($desired, $this->compose());
        $original = file_get_contents($target);

        $result = $sync->sync(apply: true);

        self::assertSame($desired, file_get_contents($target));
        self::assertNotNull($result->backupPath);
        self::assertFileExists($result->backupPath);
        self::assertSame($original, file_get_contents($result->backupPath), 'the backup must be the exact previous topology');
        self::assertFileDoesNotExist($target . '.incoming', 'the staging file must not survive a successful write');
    }

    /**
     * Fail closed. Replacing a working topology with a broken one would go unnoticed until the next
     * compose invocation — quite possibly during an incident — so every rejection leaves the live
     * file untouched.
     */
    public function testItRefusesToWriteAnEmptyOrServicelessSource(): void
    {
        foreach (['', "   \n\n", "volumes:\n  data:\n"] as $bad) {
            [$sync, , $target] = $this->sync($bad, $this->compose());
            $before = file_get_contents($target);

            try {
                $sync->sync(apply: true);
                self::fail('a source with no services must be refused');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('refusing', $e->getMessage());
            }

            self::assertSame($before, file_get_contents($target), 'a refused sync must not touch the host file');
        }
    }

    public function testAMissingSourceIsRefusedRatherThanTreatedAsEmpty(): void
    {
        $target = $this->dir . '/target.yaml';
        file_put_contents($target, $this->compose());

        $sync = new ComposeTopologySync($this->dir . '/does-not-exist.yaml', $target);

        $this->expectExceptionMessageMatches('/not found in the release image/');

        $sync->sync(apply: true);
    }

    /** A fresh box has no topology to protect, and nothing to diff. */
    public function testAHostWithNoTopologyGetsOneInstalled(): void
    {
        [$sync, , $target] = $this->sync($this->compose(), null);

        $result = $sync->sync(apply: true);

        self::assertSame('installed', $result->status);
        self::assertFileExists($target);
        self::assertNull($result->backupPath);
    }

    /**
     * `volumes:` and the `x-` extension anchors this deployment uses for shared logging have
     * children at the same indentation as a service name. Counting them as services would report
     * drift in things that are not services at all.
     */
    public function testTopLevelMapsAreNotMistakenForServices(): void
    {
        $withAnchor = "x-logging: &log\n  driver: json-file\n" . $this->compose();

        [$sync] = $this->sync($withAnchor, $withAnchor);

        self::assertSame('in_sync', $sync->sync(apply: true)->status);
    }
}
