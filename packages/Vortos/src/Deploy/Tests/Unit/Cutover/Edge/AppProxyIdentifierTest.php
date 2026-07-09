<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\AppProxyIdentifier;
use Vortos\Deploy\Exception\EdgeBaseConfigException;

final class AppProxyIdentifierTest extends TestCase
{
    private AppProxyIdentifier $identifier;

    protected function setUp(): void
    {
        $this->identifier = new AppProxyIdentifier();
    }

    /**
     * @param list<array<string,mixed>> $handlers
     * @return array<string,mixed>
     */
    private function serverWithHandlers(array $handlers, string $host = 'example.com'): array
    {
        return [
            'apps' => ['http' => ['servers' => ['srv0' => [
                'listen' => [':443'],
                'routes' => [[
                    'match' => [['host' => [$host]]],
                    'handle' => $handlers,
                ]],
            ]]]],
        ];
    }

    public function testPatchesSingleAppProxy(): void
    {
        $config = $this->serverWithHandlers([
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]],
        ]);

        $result = $this->identifier->identify($config, 'example.com');

        self::assertFalse($result->isInsert);
        self::assertNotNull($result->location);
        self::assertSame(['apps', 'http', 'servers', 'srv0', 'routes', 0, 'handle', 0], $result->location->handlerPath);
    }

    public function testIgnoresNonAppProxy(): void
    {
        // A storage proxy dialing something else must NOT be treated as the app proxy.
        $config = $this->serverWithHandlers([
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'minio:9000']]],
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-green:8080']]],
        ]);

        $result = $this->identifier->identify($config, 'example.com');

        self::assertFalse($result->isInsert);
        self::assertSame(['apps', 'http', 'servers', 'srv0', 'routes', 0, 'handle', 1], $result->location->handlerPath);
    }

    public function testFindsAppProxyNestedInSubroute(): void
    {
        // Caddy adapt wraps site-block handlers in a subroute.
        $config = $this->serverWithHandlers([
            ['handler' => 'subroute', 'routes' => [
                ['handle' => [['handler' => 'encode']]],
                ['handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]]]],
            ]],
        ]);

        $result = $this->identifier->identify($config, 'example.com');

        self::assertFalse($result->isInsert);
        self::assertSame(
            ['apps', 'http', 'servers', 'srv0', 'routes', 0, 'handle', 0, 'routes', 1, 'handle', 0],
            $result->location->handlerPath,
        );
    }

    public function testFailsClosedOnTwoAppProxies(): void
    {
        $config = $this->serverWithHandlers([
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]],
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-green:8080']]],
        ]);

        $this->expectException(EdgeBaseConfigException::class);
        $this->expectExceptionMessageMatches('/found 2 reverse_proxy/');
        $this->identifier->identify($config, 'example.com');
    }

    public function testInsertsWhenSiteBlockHasNoAppProxy(): void
    {
        $config = $this->serverWithHandlers([
            ['handler' => 'static_response', 'body' => 'hi'],
        ]);

        $result = $this->identifier->identify($config, 'example.com');

        self::assertTrue($result->isInsert);
        self::assertSame(['apps', 'http', 'servers', 'srv0', 'routes', 0, 'handle'], $result->insertHandlePath);
    }

    public function testDomainScopingIgnoresOtherSite(): void
    {
        // Two domains, each with an app proxy — only the in-scope domain's proxy counts, so no ambiguity.
        $config = [
            'apps' => ['http' => ['servers' => ['srv0' => ['routes' => [
                ['match' => [['host' => ['other.com']]], 'handle' => [
                    ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]],
                ]],
                ['match' => [['host' => ['example.com']]], 'handle' => [
                    ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-green:8080']]],
                ]],
            ]]]]],
        ];

        $result = $this->identifier->identify($config, 'example.com');

        self::assertFalse($result->isInsert);
        self::assertSame(['apps', 'http', 'servers', 'srv0', 'routes', 1, 'handle', 0], $result->location->handlerPath);
    }

    public function testFailsClosedWhenNoSiteBlockForDomain(): void
    {
        $config = [
            'apps' => ['http' => ['servers' => ['srv0' => ['routes' => [
                ['match' => [['host' => ['other.com']]], 'handle' => [
                    ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'minio:9000']]],
                ]],
            ]]]]],
        ];

        $this->expectException(EdgeBaseConfigException::class);
        $this->identifier->identify($config, 'example.com');
    }
}
