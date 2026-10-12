<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\SshCompose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfigResolver;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Driver\SshCompose\EdgeServiceReconciler;
use Vortos\Deploy\Exception\CommandFailedException;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

final class EdgeServiceReconcilerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vortos-edge-rec-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);
    }

    private function reconciler(SshTransportInterface $transport): EdgeServiceReconciler
    {
        return new EdgeServiceReconciler(
            $transport,
            new EdgeConfigGenerator(),
            new EdgeBaseConfigResolver($this->root),
            '/opt/vortos/edge',
            'docker-compose.edge.yml',
            'vortos',
            'caddy:2-alpine',
            null,
        );
    }

    public function testConvergesWhenMarkerAbsent(): void
    {
        $transport = new RecordingTransport(catStdout: '', catExit: 1);

        $outcome = $this->reconciler($transport)->reconcile('api.example.com', 'repo/app@sha256:abc');

        self::assertTrue($outcome->converged);
        // A "docker compose ... up -d" must have run.
        $ran = array_map(static fn (RemoteCommand $c): string => implode(' ', $c->argv), $transport->ran);
        self::assertNotEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'compose') && str_contains($c, 'up')));
    }

    public function testUpInjectsAppImageEnv(): void
    {
        $transport = new RecordingTransport(catStdout: '', catExit: 1);

        $this->reconciler($transport)->reconcile('api.example.com', 'repo/app@sha256:abc');

        // The compose up must carry VORTOS_APP_IMAGE via an env prefix so edge-init can interpolate it.
        $up = array_values(array_filter(
            $transport->ran,
            static fn (RemoteCommand $c): bool => in_array('up', $c->argv, true),
        ));
        self::assertNotEmpty($up);
        self::assertSame('env', $up[0]->argv[0]);
        self::assertContains('VORTOS_APP_IMAGE=repo/app@sha256:abc', $up[0]->argv);
        // The hardened edge reads its config through the config directory's group.
        self::assertContains('EDGE_CONFIG_GID=1000', $up[0]->argv);

        // Must NOT pass --remove-orphans: the edge compose project can be shared with unrelated
        // services (db/redis/kafka on a single box); --remove-orphans would tear them down.
        self::assertNotContains('--remove-orphans', $up[0]->argv);
    }

    public function testFailsClosedWhenAppImageMissing(): void
    {
        $transport = new RecordingTransport(catStdout: '', catExit: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VORTOS_APP_IMAGE');
        $this->reconciler($transport)->reconcile('api.example.com', null);
    }

    public function testSkipsWhenMarkerMatches(): void
    {
        // First run to learn the desired hash, then feed it back as the marker.
        $first = new RecordingTransport(catStdout: '', catExit: 1);
        $hash = $this->reconciler($first)->reconcile('api.example.com', 'repo/app@sha256:abc')->hash;

        $second = new RecordingTransport(catStdout: $hash, catExit: 0);
        $outcome = $this->reconciler($second)->reconcile('api.example.com', 'repo/app@sha256:abc');

        self::assertFalse($outcome->converged);
        $ran = array_map(static fn (RemoteCommand $c): string => implode(' ', $c->argv), $second->ran);
        self::assertEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'up')), 'must not recreate when unchanged');
    }

    public function testPassesTheDeclaredAdminBindAndReconvergesWhenItChanges(): void
    {
        $reconciler = fn (?string $listen, RecordingTransport $t): EdgeServiceReconciler => new EdgeServiceReconciler(
            $t, new EdgeConfigGenerator(), new EdgeBaseConfigResolver($this->root), adminListen: $listen,
        );

        $first = new RecordingTransport(catStdout: '', catExit: 1);
        $hash = $reconciler('edge-admin:2019', $first)->reconcile('api.example.com', 'repo/app@sha256:abc')->hash;
        $up = array_values(array_filter($first->ran, static fn (RemoteCommand $c): bool => in_array('up', $c->argv, true)));
        self::assertContains('CADDY_ADMIN_LISTEN=edge-admin:2019', $up[0]->argv);

        // Same compose, different admin bind: must not be skipped as converged.
        $second = new RecordingTransport(catStdout: $hash, catExit: 0);
        self::assertTrue($reconciler('0.0.0.0:2019', $second)->reconcile('api.example.com', 'repo/app@sha256:abc')->converged);
    }

    public function testRefusesWhenTheConfigGroupCannotBeRead(): void
    {
        // Without the group the hardened edge cannot read its boot config and would crash-loop
        // after the deploy reported success; refusing here keeps the old edge serving.
        $transport = new RecordingTransport(catStdout: '', catExit: 1, configGid: '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('group');
        $this->reconciler($transport)->reconcile('api.example.com', 'repo/app@sha256:abc');
    }

    public function testAbortsWhenComposeUpFails(): void
    {
        $transport = new RecordingTransport(catStdout: '', catExit: 1, failUp: true);

        $this->expectException(CommandFailedException::class);
        $this->reconciler($transport)->reconcile('api.example.com', 'repo/app@sha256:abc');
    }
}

final class RecordingTransport implements SshTransportInterface
{
    /** @var list<RemoteCommand> */
    public array $ran = [];

    public function __construct(
        private readonly string $catStdout,
        private readonly int $catExit,
        private readonly bool $failUp = false,
        private readonly string $configGid = '1000',
    ) {}

    public function run(RemoteCommand $command): CommandResult
    {
        $this->ran[] = $command;
        $joined = implode(' ', $command->argv);

        if (str_starts_with($joined, 'cat ')) {
            return new CommandResult($this->catExit, $this->catStdout, '', 0.0);
        }

        if (str_starts_with($joined, 'stat ')) {
            return new CommandResult(0, $this->configGid . "\n", '', 0.0);
        }

        if (str_contains($joined, 'compose') && str_contains($joined, 'up')) {
            return new CommandResult($this->failUp ? 1 : 0, '', $this->failUp ? 'boom' : '', 0.0);
        }

        return new CommandResult(0, '', '', 0.0);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void {}

    public function openLocalForward(int $remotePort): int
    {
        return 0;
    }

    public function closeLocalForward(int $localPort, int $remotePort): void {}
}
