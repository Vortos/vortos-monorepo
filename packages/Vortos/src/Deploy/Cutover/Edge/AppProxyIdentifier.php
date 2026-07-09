<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

use Vortos\Deploy\Exception\EdgeBaseConfigException;

/**
 * Identifies the framework-owned app reverse_proxy in an adapted Caddy JSON tree.
 *
 * Identification is by the placeholder upstream, NOT by "the route for the domain": the framework owns
 * the reverse_proxy whose upstream dials an "app-<color>" host (the blue/green naming
 * {@see \Vortos\Deploy\Compose\ComposeProjectFactory} uses everywhere). Proxies dialing other hosts
 * (object storage, third-party) are left untouched.
 *
 * The search is DOMAIN-SCOPED: only routes serving the configured app_domain (or, when app_domain is
 * unset, all routes) are considered. This is what keeps a multi-site box correct — two domains each
 * with their own app proxy on one edge do not collide. Caddy's adapt nests site-block handlers inside
 * "subroute" handlers, so the walk is recursive.
 *
 * Counting rule (§4.4):
 *  - exactly one app-color proxy in scope  -> PATCH it
 *  - zero, but the domain's site block exists -> INSERT one
 *  - zero and no site block for the domain   -> fail closed
 *  - two or more                            -> fail closed (genuinely ambiguous)
 */
final class AppProxyIdentifier
{
    private const COLORS = ['blue', 'green'];

    public function identify(array $config, string $appDomain): AppProxyIdentification
    {
        $servers = $config['apps']['http']['servers'] ?? null;
        if (!is_array($servers)) {
            throw EdgeBaseConfigException::noSiteBlockForDomain($appDomain);
        }

        /** @var list<AppProxyLocation> $matches */
        $matches = [];
        $siteBlock = null; // first in-scope route we could insert into

        foreach ($servers as $serverName => $server) {
            $routes = $server['routes'] ?? null;
            if (!is_array($routes)) {
                continue;
            }

            foreach ($routes as $routeIndex => $route) {
                if (!is_array($route) || !$this->routeInScope($route, $appDomain)) {
                    continue;
                }

                $handlePath = ['apps', 'http', 'servers', (string) $serverName, 'routes', $routeIndex, 'handle'];
                if ($siteBlock === null && isset($route['handle']) && is_array($route['handle'])) {
                    $siteBlock = ['path' => $handlePath, 'server' => (string) $serverName];
                }

                $handle = $route['handle'] ?? [];
                if (is_array($handle)) {
                    $this->collectAppProxies(
                        $handle,
                        $handlePath,
                        (string) $serverName,
                        $appDomain,
                        $matches,
                    );
                }
            }
        }

        $count = \count($matches);

        if ($count > 1) {
            throw EdgeBaseConfigException::ambiguousAppProxy($appDomain, $count);
        }

        if ($count === 1) {
            return AppProxyIdentification::patch($matches[0]);
        }

        if ($siteBlock !== null) {
            /** @var list<string|int> $path */
            $path = $siteBlock['path'];

            return AppProxyIdentification::insert($path, $siteBlock['server'], $appDomain);
        }

        throw EdgeBaseConfigException::noSiteBlockForDomain($appDomain);
    }

    /** @param array<string, mixed> $route */
    private function routeInScope(array $route, string $appDomain): bool
    {
        if ($appDomain === '') {
            return true;
        }

        $hosts = $this->routeHosts($route);

        // A route with no host matcher is a catch-all — it serves the domain too, so it is in scope
        // (this supports a single-site Caddyfile written without an explicit host matcher). A route
        // WITH host matchers is in scope only if one of them names the domain.
        if ($hosts === []) {
            return true;
        }

        return in_array($appDomain, $hosts, true);
    }

    /**
     * @param array<string, mixed> $route
     * @return list<string>
     */
    private function routeHosts(array $route): array
    {
        $hosts = [];
        $matchers = $route['match'] ?? [];
        if (!is_array($matchers)) {
            return [];
        }

        foreach ($matchers as $matcher) {
            if (is_array($matcher) && isset($matcher['host']) && is_array($matcher['host'])) {
                foreach ($matcher['host'] as $host) {
                    if (is_string($host)) {
                        $hosts[] = $host;
                    }
                }
            }
        }

        return $hosts;
    }

    /**
     * Recursively walk a handler list, collecting every reverse_proxy handler whose upstream dials
     * app-<color>. Descends into subroute handlers (Caddy adapt wraps site-block handlers there).
     *
     * @param list<mixed>          $handlers
     * @param list<string|int>     $path      JSON path to $handlers
     * @param list<AppProxyLocation> $matches out-param
     */
    private function collectAppProxies(array $handlers, array $path, string $serverName, string $domain, array &$matches): void
    {
        foreach ($handlers as $index => $handler) {
            if (!is_array($handler)) {
                continue;
            }

            $handlerType = $handler['handler'] ?? null;

            if ($handlerType === 'reverse_proxy' && $this->dialsAppColor($handler)) {
                $matches[] = new AppProxyLocation([...$path, $index], $serverName, $domain);
                continue;
            }

            if ($handlerType === 'subroute') {
                $this->descendSubroute($handler, [...$path, $index], $serverName, $domain, $matches);
            }
        }
    }

    /**
     * @param array<string, mixed> $subroute
     * @param list<string|int>     $path      JSON path to the subroute handler
     * @param list<AppProxyLocation> $matches out-param
     */
    private function descendSubroute(array $subroute, array $path, string $serverName, string $domain, array &$matches): void
    {
        $routes = $subroute['routes'] ?? [];
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $routeIndex => $route) {
            if (!is_array($route) || !isset($route['handle']) || !is_array($route['handle'])) {
                continue;
            }

            $this->collectAppProxies(
                $route['handle'],
                [...$path, 'routes', $routeIndex, 'handle'],
                $serverName,
                $domain,
                $matches,
            );
        }
    }

    /** @param array<string, mixed> $handler */
    private function dialsAppColor(array $handler): bool
    {
        $upstreams = $handler['upstreams'] ?? [];
        if (!is_array($upstreams)) {
            return false;
        }

        foreach ($upstreams as $upstream) {
            if (is_array($upstream) && isset($upstream['dial']) && is_string($upstream['dial'])) {
                if ($this->isAppColorDial($upstream['dial'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAppColorDial(string $dial): bool
    {
        // Host portion before an optional :port. app-blue / app-green (+ any port).
        $host = str_contains($dial, ':') ? substr($dial, 0, strrpos($dial, ':')) : $dial;

        foreach (self::COLORS as $color) {
            if ($host === 'app-' . $color) {
                return true;
            }
        }

        return false;
    }
}
