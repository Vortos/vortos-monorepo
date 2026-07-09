<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

use Vortos\Deploy\Cutover\DesiredRoute;

/**
 * Merges the live blue/green upstream into an operator's adapted base config — the heart of the
 * static (operator) + dynamic (framework) split.
 *
 * The merge is FIELD-LEVEL on the parsed JSON tree, never a wholesale handler replace. The framework
 * touches exactly the fields it owns on the identified app proxy:
 *  - upstreams -> the live color, e.g. [{dial: app-green:8080}];
 *  - canary    -> weighted pair + load_balancing.selection_policy = weighted_round_robin.
 * Everything else on that handler (header_up, transport, timeouts) and everywhere else in the file
 * (encode, headers, other routes) is preserved byte-for-byte. This determinism is the whole reason we
 * adapt to JSON first: a structural field patch is reproducible; string-editing a Caddyfile is not.
 *
 * TLS: the merger guarantees the domain keeps a certificate after a reload, WITHOUT overriding an
 * operator's own TLS policy (see {@see ensureTlsForDomain()}).
 *
 * This class is pure (no I/O). Identification, patch/insert, and TLS retention are all deterministic
 * functions of (adapted base, desired route).
 */
final class EdgeConfigMerger
{
    public function __construct(
        private readonly AppProxyIdentifier $identifier,
    ) {}

    /**
     * @param array<string, mixed> $adaptedBase
     */
    public function merge(array $adaptedBase, DesiredRoute $desired): MergeOutcome
    {
        $domain = $desired->domain ?? '';
        $identification = $this->identifier->identify($adaptedBase, $domain);

        if ($identification->isInsert) {
            [$config, $location] = $this->insert($adaptedBase, $identification, $desired);
            $action = MergeAction::Inserted;
        } else {
            \assert($identification->location !== null);
            $location = $identification->location;
            $config = $this->patch($adaptedBase, $location, $desired);
            $action = MergeAction::Patched;
        }

        if ($desired->domain !== null) {
            $config = $this->ensureTlsForDomain($config, $desired->domain);
        }

        return new MergeOutcome($config, $action, $location);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function patch(array $config, AppProxyLocation $location, DesiredRoute $desired): array
    {
        $handler = $this->getAt($config, $location->handlerPath);
        if (!is_array($handler)) {
            $handler = ['handler' => 'reverse_proxy'];
        }

        foreach ($this->frameworkFields($desired) as $key => $value) {
            $handler[$key] = $value;
        }

        return $this->setAt($config, $location->handlerPath, $handler);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: array<string, mixed>, 1: AppProxyLocation}
     */
    private function insert(array $config, AppProxyIdentification $identification, DesiredRoute $desired): array
    {
        \assert($identification->insertHandlePath !== null);
        $handlePath = $identification->insertHandlePath;

        $handle = $this->getAt($config, $handlePath);
        if (!is_array($handle)) {
            $handle = [];
        }

        $handler = ['handler' => 'reverse_proxy'];
        foreach ($this->frameworkFields($desired) as $key => $value) {
            $handler[$key] = $value;
        }
        // A minimal default health check pointing at the single source of health truth
        // (vortos-health's /health/ready). Only injected for a framework-INSERTED proxy; a
        // patched operator proxy keeps whatever health_checks (if any) the operator wrote.
        $handler['health_checks'] = [
            'active' => ['uri' => '/health/ready', 'interval' => '10s', 'timeout' => '5s'],
        ];
        $handler['flush_interval'] = -1;

        $newIndex = \count($handle);
        $handle[] = $handler;
        $config = $this->setAt($config, $handlePath, $handle);

        $location = new AppProxyLocation(
            [...$handlePath, $newIndex],
            $identification->serverName,
            $identification->domain,
        );

        return [$config, $location];
    }

    /**
     * The fields the framework owns on the app proxy: the upstream(s) and — for a canary ramp — the
     * weighted load-balancing policy. A single (100/0) route sets only upstreams and leaves any
     * operator load_balancing untouched; a canary route additionally sets weighted_round_robin so the
     * weights are honored.
     *
     * @return array<string, mixed>
     */
    private function frameworkFields(DesiredRoute $desired): array
    {
        $activeDial = sprintf('%s:%d', $desired->upstream->host, $desired->upstream->port);

        if ($desired->weight >= 100 || $desired->weight <= 0) {
            return ['upstreams' => [['dial' => $activeDial]]];
        }

        $complementDial = sprintf('app-%s:%d', $desired->activeColor->opposite()->value, $desired->upstream->port);

        return [
            'load_balancing' => ['selection_policy' => ['policy' => 'weighted_round_robin']],
            'upstreams' => [
                ['dial' => $activeDial, 'weight' => $desired->weight],
                ['dial' => $complementDial, 'weight' => 100 - $desired->weight],
            ],
        ];
    }

    /**
     * Guarantee the domain keeps a managed certificate after a reload, without clobbering operator TLS:
     *  - a policy already listing the domain in subjects -> nothing to do;
     *  - a catch-all policy (no subjects) already covers it -> leave the operator's policy as-is
     *    (respects their issuer/challenge choice);
     *  - otherwise append a minimal {subjects:[domain]} policy so Caddy manages a cert for it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureTlsForDomain(array $config, string $domain): array
    {
        $policies = $config['apps']['tls']['automation']['policies'] ?? null;

        if (is_array($policies)) {
            foreach ($policies as $policy) {
                if (!is_array($policy)) {
                    continue;
                }
                $subjects = $policy['subjects'] ?? null;
                if (!is_array($subjects) || $subjects === []) {
                    return $config; // catch-all policy already covers the domain
                }
                if (in_array($domain, $subjects, true)) {
                    return $config; // domain already explicitly covered
                }
            }
        } else {
            $policies = [];
        }

        $policies[] = ['subjects' => [$domain]];
        $config['apps']['tls']['automation']['policies'] = $policies;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string|int>     $path
     */
    private function getAt(array $config, array $path): mixed
    {
        $cursor = $config;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string|int>     $path
     * @return array<string, mixed>
     */
    private function setAt(array $config, array $path, mixed $value): array
    {
        $key = $path[0];
        if (\count($path) === 1) {
            $config[$key] = $value;

            return $config;
        }

        $child = $config[$key] ?? [];
        if (!is_array($child)) {
            $child = [];
        }

        $config[$key] = $this->setAt($child, array_slice($path, 1), $value);

        return $config;
    }
}
