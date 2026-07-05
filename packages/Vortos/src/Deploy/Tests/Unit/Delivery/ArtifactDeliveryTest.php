<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Delivery\ArtifactDelivery;
use Vortos\Deploy\Delivery\DeliveryArtifact;
use Vortos\Deploy\Delivery\DeliveryManifest;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

/**
 * G3: config/secret delivery to the VPS is first-class, validated, atomic and fail-closed.
 */
final class ArtifactDeliveryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/vortos-delivery-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function test_traversal_paths_are_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryArtifact('/tmp/x', '../escape', '0644');
    }

    public function test_missing_required_artifact_fails_closed_before_touching_target(): void
    {
        $manifest = new DeliveryManifest([
            new DeliveryArtifact($this->tmp . '/does-not-exist', '.env.prod', '0600', required: true),
        ]);
        $transport = new RecordingTransport();

        $this->expectException(\RuntimeException::class);
        try {
            (new ArtifactDelivery($transport))->deliver($manifest, '/opt/vortos');
        } finally {
            self::assertSame([], $transport->copies, 'No files may be shipped when a required artifact is missing.');
        }
    }

    public function test_delivers_atomically_and_preserves_modes(): void
    {
        file_put_contents($this->tmp . '/.env.prod', 'APP_ENV=prod');
        file_put_contents($this->tmp . '/vortos-secrets.age', 'ciphertext');

        $manifest = new DeliveryManifest([
            new DeliveryArtifact($this->tmp . '/.env.prod', '.env.prod', '0600', required: true),
            // B15: the age store ships 0640 (owner+group read) so the container uid can read it.
            new DeliveryArtifact($this->tmp . '/vortos-secrets.age', 'vortos-secrets.age', '0640', required: false),
        ]);
        $transport = new RecordingTransport();

        (new ArtifactDelivery($transport))->deliver($manifest, '/opt/vortos');

        // Everything is staged under an incoming dir, never written straight into the deploy dir, and
        // each artifact's declared mode is preserved.
        foreach ($transport->copies as [$local, $remote, $mode]) {
            self::assertStringContainsString('/.incoming-', $remote);
            if (str_contains((string) $remote, 'vortos-secrets.age')) {
                self::assertSame('0640', $mode, 'The age store must be shipped 0640 (B15).');
            }
            if (str_ends_with((string) $remote, '.env.prod')) {
                self::assertSame('0600', $mode, '.env.prod stays owner-only 0600.');
            }
        }

        // The last command is the atomic swap into the deploy dir followed by staging cleanup.
        $lastRun = $transport->runs[array_key_last($transport->runs)];
        $joined = implode(' ', $lastRun);
        self::assertStringContainsString('cp -a', $joined);
        self::assertStringContainsString('/opt/vortos', $joined);
        self::assertStringContainsString('rm -rf', $joined);
    }
}

final class RecordingTransport implements SshTransportInterface
{
    /** @var list<array{0:string,1:string,2:string}> */
    public array $copies = [];
    /** @var list<list<string>> */
    public array $runs = [];

    public function run(RemoteCommand $command): CommandResult
    {
        $this->runs[] = $command->argv;

        return new CommandResult(0, '', '', 0.0);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
    {
        $this->copies[] = [$localPath, $remotePath, $mode];
    }

    public function openLocalForward(int $remotePort): int
    {
        return 0;
    }

    public function closeLocalForward(int $localPort, int $remotePort): void {}
}
