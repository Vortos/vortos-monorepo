<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Throwable;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyConfigFragment;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Testing\EdgeRouterConformanceTestCase;

/**
 * Proves the real Caddy driver — not just {@see \Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter}
 * — honors the {@see EdgeRouterConformanceTestCase} TCK. Needs a live Caddy admin API,
 * so it follows the same `markTestSkipped()`-on-unreachable convention as {@see
 * \Vortos\Backup\Tests\Integration\PostgresInfraTest}: skipped without Docker, runs for
 * real against the Caddy instance in the Docker Compose stack.
 */
final class CaddyEdgeRouterConformanceTest extends EdgeRouterConformanceTestCase
{
    /**
     * Reachability is probed once per test-class run, not once per TCK test method —
     * a DNS lookup that fails to resolve (the common case outside the Docker Compose
     * stack) can take several seconds per attempt, and the TCK inherits a dozen test
     * methods. Caching avoids paying that cost a dozen times over just to skip.
     */
    private static ?bool $caddyReachable = null;

    protected function createRouter(): EdgeRouterInterface
    {
        $adminBaseUrl = $_ENV['CADDY_ADMIN_URL'] ?? 'http://caddy:2019';
        $httpClient = new Client(['connect_timeout' => 2, 'timeout' => 5]);
        $adminClient = new CaddyAdminClient($httpClient, new HttpFactory(), $adminBaseUrl);

        if (self::$caddyReachable === null) {
            try {
                $adminClient->currentConfig();
                self::$caddyReachable = true;
            } catch (Throwable) {
                self::$caddyReachable = false;
            }
        }

        if (self::$caddyReachable === false) {
            $this->markTestSkipped('Caddy admin API not reachable at ' . $adminBaseUrl);
        }

        $configWriter = new MountedConfigWriter(sys_get_temp_dir() . '/caddy-conformance-upstream.json');
        $adminListen = ltrim((string) preg_replace('#^https?://#', '', $adminBaseUrl), '/');

        return new CaddyEdgeRouter(
            $adminClient,
            new CaddyConfigFragment($adminListen),
            $configWriter,
            new DrainObserver($adminClient),
        );
    }

    protected function expectedKey(): string
    {
        return 'caddy';
    }
}
