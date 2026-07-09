<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * The result of locating the framework-owned app proxy in an adapted base config.
 *
 * Two shapes:
 *  - PATCH:  exactly one reverse_proxy dialing app-<color> was found; {@see location} points at that
 *            handler array so the merger replaces only its upstreams.
 *  - INSERT: the domain's site block exists but has no app proxy; {@see insertHandlePath} points at
 *            the route "handle" list the merger appends a framework reverse_proxy to.
 *
 * The ambiguous (>=2) and no-site-block cases never produce an identification — they throw
 * {@see \Vortos\Deploy\Exception\EdgeBaseConfigException} from the identifier.
 */
final readonly class AppProxyIdentification
{
    /**
     * @param list<string|int>|null $insertHandlePath
     */
    private function __construct(
        public bool $isInsert,
        public ?AppProxyLocation $location,
        public ?array $insertHandlePath,
        public string $serverName,
        public string $domain,
    ) {}

    public static function patch(AppProxyLocation $location): self
    {
        return new self(false, $location, null, $location->serverName, $location->domain);
    }

    /** @param list<string|int> $handlePath */
    public static function insert(array $handlePath, string $serverName, string $domain): self
    {
        return new self(true, null, $handlePath, $serverName, $domain);
    }
}
