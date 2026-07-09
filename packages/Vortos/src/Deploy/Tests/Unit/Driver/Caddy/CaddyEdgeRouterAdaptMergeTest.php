<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\Caddy;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\Edge\AppProxyIdentifier;
use Vortos\Deploy\Cutover\Edge\ConfigInvariantValidator;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfigResolver;
use Vortos\Deploy\Cutover\Edge\EdgeConfigAssembler;
use Vortos\Deploy\Cutover\Edge\EdgeConfigMerger;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\Lock\EdgeCutoverLockInterface;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\CaddyfileAdapter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Target\ActiveColor;

final class CaddyEdgeRouterAdaptMergeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-edge-am-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/Caddyfile', "example.com {\n  reverse_proxy app-blue:8080\n}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/Caddyfile');
        @unlink($this->dir . '/caddy.json');
        @rmdir($this->dir);
    }

    /** @return array<string,mixed> */
    private function adaptedBase(): array
    {
        return [
            'apps' => ['http' => ['servers' => ['srv0' => [
                'listen' => [':443'],
                'routes' => [[
                    'match' => [['host' => ['example.com']]],
                    'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]]],
                ]],
            ]]]],
        ];
    }

    private function fakeRunner(string $stdout): CommandRunnerInterface
    {
        return new class($stdout) implements CommandRunnerInterface {
            public function __construct(private readonly string $stdout) {}

            public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
            {
                return new CommandResult(0, $this->stdout, '', 0.01);
            }
        };
    }

    private function assembler(): EdgeConfigAssembler
    {
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $this->fakeRunner(json_encode($this->adaptedBase(), \JSON_THROW_ON_ERROR)));

        return new EdgeConfigAssembler(
            new EdgeBaseConfigResolver($this->dir),
            $adapter,
            new EdgeConfigMerger(new AppProxyIdentifier()),
            new ConfigInvariantValidator('edge:2019'),
            new EdgeConfigGenerator(),
            'edge:2019',
            $this->dir . '/Caddyfile',
        );
    }

    private function route(ActiveColor $color = ActiveColor::Green): DesiredRoute
    {
        return new DesiredRoute(
            env: 'production',
            activeColor: $color,
            upstream: new ColorEndpoint('app-' . $color->value, 8080),
            drainDeadlineSeconds: 1,
            domain: 'example.com',
        );
    }

    public function testCutoverLoadsMergedOperatorConfig(): void
    {
        $http = new FakeCaddyHttpClient();
        $admin = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');

        $router = new CaddyEdgeRouter(
            $admin,
            new EdgeConfigGenerator(),
            new RecordingEdgeStateStore(),
            new DrainObserver($admin),
            'edge:2019',
            null,
            $this->assembler(),
        );

        $result = $router->cutover($this->route(ActiveColor::Green));

        self::assertTrue($result->succeeded);
        $loaded = json_decode($http->lastLoaded ?? '', true);
        // Operator's file drove the shape; framework injected the live color + pinned admin.
        self::assertSame('app-green:8080', $loaded['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['upstreams'][0]['dial']);
        self::assertSame('edge:2019', $loaded['admin']['listen']);
        self::assertSame(['example.com'], $loaded['apps']['tls']['automation']['policies'][0]['subjects']);
    }

    public function testRollsBackToLastKnownGoodOnVerifyFailure(): void
    {
        // /config/ always echoes a config WITHOUT the new dial, so verify fails and the router must
        // re-load the snapshot it took before switching.
        $http = new RollbackFakeHttpClient();
        $admin = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');

        $router = new CaddyEdgeRouter(
            $admin,
            new EdgeConfigGenerator(),
            new RecordingEdgeStateStore(),
            new DrainObserver($admin),
            'edge:2019',
            null,
            $this->assembler(),
        );

        try {
            $router->cutover($this->route(ActiveColor::Green));
            self::fail('expected CutoverFailedException');
        } catch (CutoverFailedException) {
            // The last load must be the rollback to the known-good snapshot.
            self::assertGreaterThanOrEqual(2, \count($http->loaded));
            self::assertStringContainsString('known-good-marker', end($http->loaded));
        }
    }

    public function testFailsClosedWhenLockHeld(): void
    {
        $http = new FakeCaddyHttpClient();
        $admin = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');

        $heldLock = new class implements EdgeCutoverLockInterface {
            public function acquire(string $env, int $ttlSeconds): ?string
            {
                return null; // always contended
            }

            public function release(string $env, string $token): void {}
        };

        $router = new CaddyEdgeRouter(
            $admin,
            new EdgeConfigGenerator(),
            new RecordingEdgeStateStore(),
            new DrainObserver($admin),
            'edge:2019',
            null,
            $this->assembler(),
            $heldLock,
        );

        $this->expectException(CutoverFailedException::class);
        $this->expectExceptionMessageMatches('/another cutover is in progress/');
        $router->cutover($this->route());
    }

    public function testBootWriteFailureRollsBackAndFails(): void
    {
        $http = new FakeCaddyHttpClient();
        $admin = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');

        // A writer whose remote copy fails cleanly (transport error), so the boot-file write throws.
        $failingTransport = new class implements \Vortos\Deploy\Execution\SshTransportInterface {
            public function run(\Vortos\Deploy\Execution\RemoteCommand $command): \Vortos\Deploy\Execution\CommandResult
            {
                return new \Vortos\Deploy\Execution\CommandResult(0, '', '', 0.0);
            }

            public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
            {
                throw new \RuntimeException('simulated SSH copy failure');
            }

            public function openLocalForward(int $remotePort): int
            {
                return 0;
            }

            public function closeLocalForward(int $localPort, int $remotePort): void {}
        };
        $writer = new MountedConfigWriter($this->dir . '/caddy.json', $failingTransport);

        $router = new CaddyEdgeRouter(
            $admin,
            new EdgeConfigGenerator(),
            new RecordingEdgeStateStore(),
            new DrainObserver($admin),
            'edge:2019',
            $writer,
            $this->assembler(),
        );

        $this->expectException(CutoverFailedException::class);
        $this->expectExceptionMessageMatches('/boot file write failed/');
        $router->cutover($this->route());
    }
}

/**
 * A fake that records every loaded config and always reports the OLD config on /config/, so verify
 * fails and the rollback path is exercised. The first /config/ (snapshot) returns the known-good.
 */
final class RollbackFakeHttpClient implements ClientInterface
{
    /** @var list<string> */
    public array $loaded = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/load')) {
            $this->loaded[] = (string) $request->getBody();

            return new Response(200);
        }

        if (str_ends_with($path, '/config/')) {
            // Always the known-good config (never the new dial) → verify mismatch + rollback target.
            return new Response(200, [], '{"admin":{"listen":"edge:2019"},"known-good-marker":true}');
        }

        if (str_ends_with($path, '/metrics')) {
            return new Response(200, [], "caddy_http_requests_in_flight 0\n");
        }

        return new Response(404);
    }
}
