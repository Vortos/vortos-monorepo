<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\ObjectStore\Command\ObjectStoreMultipartCommand;
use Vortos\ObjectStore\Contract\ServerSideMultipartMaintenanceInterface;
use Vortos\ObjectStore\ValueObject\MultipartUpload;

final class ObjectStoreMultipartCommandTest extends TestCase
{
    public function test_list_outputs_uploads(): void
    {
        $tester = new CommandTester(new ObjectStoreMultipartCommand(new FakeMultipartMaintenance([
            new MultipartUpload('tmp/a.bin', 'upload-1', new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        ])));

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        $this->assertStringContainsString('tmp/a.bin', $tester->getDisplay());
    }

    public function test_abort_requires_confirmation(): void
    {
        $tester = new CommandTester(new ObjectStoreMultipartCommand(new FakeMultipartMaintenance()));

        $this->assertSame(Command::FAILURE, $tester->execute([
            'action' => 'abort',
            '--key' => 'tmp/a.bin',
            '--upload-id' => 'upload-1',
        ]));
        $this->assertStringContainsString('requires --confirm', $tester->getDisplay());
    }

    public function test_abort_stale_dry_run_does_not_abort(): void
    {
        $maintenance = new FakeMultipartMaintenance([
            new MultipartUpload('tmp/a.bin', 'upload-1', new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]);
        $tester = new CommandTester(new ObjectStoreMultipartCommand($maintenance));

        $this->assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'abort-stale',
            '--older-than' => '2026-01-02T00:00:00Z',
            '--dry-run' => true,
        ]));
        $this->assertSame(0, $maintenance->aborted);
        $this->assertStringContainsString('1 stale multipart upload(s) would be aborted', $tester->getDisplay());
    }
}

final class FakeMultipartMaintenance implements ServerSideMultipartMaintenanceInterface
{
    public int $aborted = 0;

    /** @param MultipartUpload[] $uploads */
    public function __construct(private readonly array $uploads = []) {}

    public function list(?string $prefix = null): array
    {
        return $this->uploads;
    }

    public function abort(string $key, string $uploadId): void
    {
        ++$this->aborted;
    }

    public function abortStale(\DateTimeImmutable $olderThan, ?string $prefix = null): int
    {
        foreach ($this->uploads as $upload) {
            if ($upload->initiatedAt < $olderThan) {
                ++$this->aborted;
            }
        }

        return $this->aborted;
    }
}
