<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * Which networks the edge (Caddy) and its config one-shot join.
 *
 * ## Why the edge is not simply on the app network
 *
 * The edge is the only container the internet talks to, so it is the likeliest to be compromised —
 * and on the shared app network it can reach the database, the cache, the broker and the Docker API
 * proxy directly. It needs exactly one of those things: the app colors it forwards to. So it is
 * placed on a serving network it shares with the colors and nothing else.
 *
 * ## Why the admin API gets a network of its own
 *
 * Caddy's admin API reconfigures routing and has no authentication. Bound on the serving network,
 * a compromised app color could repoint the public site; bound on the app network, so could anything
 * there. Bound to the edge's address on a dedicated admin network ("adminAlias:2019"), it answers
 * only the deploy one-shots that join that network, and refuses everyone else at the socket —
 * measured, not assumed.
 *
 * ## Why edge-init stays on the app network
 *
 * It renders the boot config from the durable state store (the cache), so it needs the data plane.
 * It is a one-shot from the app image that exits before the edge starts and accepts no connections.
 */
final readonly class EdgeNetworkTopology
{
    public const DEFAULT_ADMIN_ALIAS = 'edge-admin';

    public function __construct(
        public string $servingNetwork,
        public string $initNetwork,
        public ?string $adminNetwork = null,
        public string $adminAlias = self::DEFAULT_ADMIN_ALIAS,
    ) {
        foreach (['servingNetwork' => $servingNetwork, 'initNetwork' => $initNetwork, 'adminAlias' => $adminAlias] as $field => $value) {
            self::assertName($field, $value);
        }

        if ($adminNetwork !== null) {
            self::assertName('adminNetwork', $adminNetwork);

            if ($adminNetwork === $servingNetwork) {
                throw new \InvalidArgumentException(
                    'EdgeNetworkTopology.adminNetwork must differ from the serving network: the app colors are on that one.',
                );
            }
        }
    }

    /** Everything on one network — the topology before the edge was isolated. */
    public static function shared(string $network): self
    {
        return new self($network, $network);
    }

    /** @return list<string> */
    public function networks(): array
    {
        return array_values(array_unique(array_filter([$this->servingNetwork, $this->initNetwork, $this->adminNetwork])));
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'serving_network' => $this->servingNetwork,
            'init_network' => $this->initNetwork,
            'admin_network' => $this->adminNetwork,
            'admin_alias' => $this->adminAlias,
        ];
    }

    private static function assertName(string $field, string $value): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'EdgeNetworkTopology.%s must be a Docker network or alias name; got "%s".',
                $field,
                $value,
            ));
        }
    }
}
