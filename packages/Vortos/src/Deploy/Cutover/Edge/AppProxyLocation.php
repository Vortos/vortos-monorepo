<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * Where, in the adapted Caddy JSON tree, the framework-owned app reverse_proxy handler lives.
 *
 * Produced by {@see AppProxyIdentifier}, consumed by {@see EdgeConfigMerger} to field-patch ONLY that
 * handler's upstreams (never a wholesale replace). The path is a list of array keys walked from the
 * config root down to the reverse_proxy handler array, e.g.
 * ['apps','http','servers','app','routes',0,'handle',1]. Keeping the location explicit (rather than
 * re-searching at merge time) is what makes the merge deterministic and auditable.
 */
final readonly class AppProxyLocation
{
    /**
     * @param list<string|int> $handlerPath keys from config root to the reverse_proxy handler array
     * @param string           $serverName  the http.servers key the handler was found under
     * @param string           $domain      the host matcher (app_domain) the handler serves
     */
    public function __construct(
        public array $handlerPath,
        public string $serverName,
        public string $domain,
    ) {
        if ($handlerPath === []) {
            throw new \InvalidArgumentException('App proxy handler path must not be empty.');
        }
    }
}
