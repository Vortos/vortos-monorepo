<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * Port: turn an operator base config into a structured edge JSON tree.
 *
 * The domain (Cutover) depends on this port, never on a concrete proxy driver, so the adapt mechanism
 * stays swappable behind the same {@see \Vortos\Deploy\Cutover\EdgeRouterInterface} driver seam (a
 * future envoy adapter would implement this too). The current implementation runs the proxy's own
 * parser in a throwaway container.
 */
interface EdgeConfigAdapterInterface
{
    /**
     * @return array<string, mixed> the adapted (or parsed, for a JSON base) config tree
     *
     * @throws \Vortos\Deploy\Exception\EdgeBaseConfigException on a parse failure or oversize output
     */
    public function adapt(EdgeBaseConfig $base): array;
}
