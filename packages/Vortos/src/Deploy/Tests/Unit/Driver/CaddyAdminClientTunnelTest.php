<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Tests\Fixtures\FakeSshTransport;

final class CaddyAdminClientTunnelTest extends TestCase
{
    private function capturingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            /** @var list<string> */
            public array $uris = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->uris[] = (string) $request->getUri();

                return new Response(200, [], '{}');
            }
        };
    }

    public function test_push_mode_routes_admin_calls_through_an_ssh_tunnel(): void
    {
        $client = $this->capturingClient();
        $transport = new FakeSshTransport();
        $transport->nextLocalForwardPort = 14019;

        $admin = new CaddyAdminClient($client, new HttpFactory(), 'http://localhost:2019', $transport, 2019);

        $admin->currentConfig();
        $admin->load(['apps' => []]);

        // Admin API is reached via the tunneled loopback port, never the static URL.
        $this->assertSame('http://127.0.0.1:14019/config/', $client->uris[0]);
        $this->assertSame('http://127.0.0.1:14019/load', $client->uris[1]);

        // The forward to Caddy's admin port (2019) is opened exactly once and reused.
        $this->assertCount(1, $transport->forwards);
        $this->assertSame(2019, $transport->forwards[0]['remote']);
    }

    public function test_local_mode_uses_static_admin_url_without_a_tunnel(): void
    {
        $client = $this->capturingClient();

        $admin = new CaddyAdminClient($client, new HttpFactory(), 'http://localhost:2019');
        $admin->currentConfig();

        $this->assertSame('http://localhost:2019/config/', $client->uris[0]);
    }
}
