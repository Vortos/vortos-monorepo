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
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-D: a Caddy cutover pushes a TLS-preserving config to the live admin API AND persists the
 * routing intent to the edge state store (replacing the unreachable /etc/caddy/ write). Driven
 * against a real CaddyAdminClient backed by a fake PSR-18 client, so the whole cutover path runs.
 */
final class CaddyEdgeRouterCutoverTest extends TestCase
{
    public function test_cutover_loads_tls_config_and_persists_state(): void
    {
        $http = new FakeCaddyHttpClient();
        $adminClient = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');
        $store = new RecordingEdgeStateStore();

        $router = new CaddyEdgeRouter(
            $adminClient,
            new EdgeConfigGenerator(),
            $store,
            new DrainObserver($adminClient),
            'edge:2019',
        );

        $result = $router->cutover(new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8080),
            drainDeadlineSeconds: 1,
            domain: 'api.example.com',
        ));

        self::assertTrue($result->succeeded);

        // The live /load must have carried the domain's tls.automation (cert preserved).
        $loaded = json_decode($http->lastLoaded ?? '', true);
        self::assertSame(['api.example.com'], $loaded['apps']['tls']['automation']['policies'][0]['subjects']);
        self::assertSame('edge:2019', $loaded['admin']['listen']);

        // The routing intent must have been persisted for edge-restart reconstruction.
        self::assertNotNull($store->saved);
        self::assertSame(ActiveColor::Blue, $store->saved->activeColor);
        self::assertSame('api.example.com', $store->saved->domain);
        self::assertSame('production', $store->saved->env);
    }

    /**
     * The cutover must persist the EXACT rendered config to the edge's on-disk boot file, so a bare
     * Docker daemon restart (which never re-runs edge-init) reloads the CURRENT route from disk instead
     * of coming up empty or on a stale color. This is the durability half of the daemon-restart fix.
     */
    public function test_cutover_persists_rendered_config_to_boot_file(): void
    {
        $http = new FakeCaddyHttpClient();
        $adminClient = new CaddyAdminClient($http, new HttpFactory(), 'http://edge:2019');

        $bootFile = sys_get_temp_dir() . '/vortos-edge-boot-' . bin2hex(random_bytes(6)) . '/caddy.json';
        $writer = new MountedConfigWriter($bootFile);

        $router = new CaddyEdgeRouter(
            $adminClient,
            new EdgeConfigGenerator(),
            new RecordingEdgeStateStore(),
            new DrainObserver($adminClient),
            'edge:2019',
            $writer,
        );

        $router->cutover(new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8080),
            drainDeadlineSeconds: 1,
            domain: 'api.example.com',
        ));

        self::assertFileExists($bootFile);
        $persisted = json_decode((string) file_get_contents($bootFile), true, 512, \JSON_THROW_ON_ERROR);

        // The boot file must equal what was pushed live: same upstream dial, same admin bind, and it
        // must retain the domain's tls.automation so a cold boot keeps serving the cert.
        self::assertSame('app-blue:8080', $persisted['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'][0]['dial']);
        self::assertSame('edge:2019', $persisted['admin']['listen']);
        self::assertSame(['api.example.com'], $persisted['apps']['tls']['automation']['policies'][0]['subjects']);
        self::assertSame(json_decode($http->lastLoaded ?? '', true), $persisted);

        @unlink($bootFile);
        @rmdir(\dirname($bootFile));
    }
}

/**
 * A URL-aware fake Caddy admin: records the loaded config, echoes it back on /config/, and reports a
 * drained (0 in-flight) /metrics so the cutover verifies clean.
 */
final class FakeCaddyHttpClient implements ClientInterface
{
    public ?string $lastLoaded = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/load')) {
            $this->lastLoaded = (string) $request->getBody();

            return new Response(200);
        }

        if (str_ends_with($path, '/config/')) {
            return new Response(200, [], $this->lastLoaded ?? 'null');
        }

        if (str_ends_with($path, '/metrics')) {
            return new Response(200, [], "caddy_http_requests_in_flight 0\n");
        }

        return new Response(404);
    }
}

final class RecordingEdgeStateStore implements EdgeStateStoreInterface
{
    public ?EdgeState $saved = null;

    public function load(string $env): ?EdgeState
    {
        return $this->saved;
    }

    public function save(EdgeState $state): EdgeState
    {
        $this->saved = $state->withVersion(1, '2026-07-05T00:00:00+00:00');

        return $this->saved;
    }
}
