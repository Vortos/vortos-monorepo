<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

use Vortos\Deploy\Exception\EdgeBaseConfigException;

/**
 * The config firewall: the last gate a merged edge config passes before it can be /load-ed or written
 * to the durable boot file.
 *
 * The merged config that reaches the admin API can reconfigure the ENTIRE edge, so a hand-written
 * base config is a trust boundary that must be enforced structurally, not assumed. This validator:
 *
 *  1. Refuses any operator override of the admin listen, then FORCE-PINS the admin block to the
 *     framework's canonical value (mirrors {@see \Vortos\Deploy\Cutover\EdgeConfigGenerator} assemble),
 *     so the crown-jewel admin API can never be accidentally exposed or rebound by the Caddyfile.
 *  2. Refuses any HTTP server that binds a privileged port other than 80/443.
 *  3. Asserts the domain retains TLS coverage (explicit subject, catch-all policy, or automatic HTTPS
 *     over a host matcher) — a reload must never clobber the certificate.
 *  4. Diffs the final config against the adapted base and refuses if anything OTHER than the app
 *     upstream, the TLS policy, or the admin block changed — defense in depth against a merge bug
 *     silently mutating unrelated operator config.
 *
 * Any violation throws {@see EdgeBaseConfigException} (secret-free). On success it returns the
 * force-pinned config ready to load.
 */
final class ConfigInvariantValidator
{
    private const ALLOWED_PRIVILEGED_PORTS = [80, 443];

    public function __construct(
        private readonly string $adminListen = 'localhost:2019',
    ) {}

    /**
     * @param array<string, mixed> $adaptedBase the config BEFORE the framework touched it
     * @param MergeOutcome         $merged      the merger's output
     * @param string|null          $domain      the app domain (TLS subject), or null for an internal edge
     * @return array<string, mixed> the force-pinned, validated config
     */
    public function validate(array $adaptedBase, MergeOutcome $merged, ?string $domain): array
    {
        $config = $merged->config;

        $config = $this->pinAdmin($config);
        $this->assertNoPrivilegedListeners($config);

        if ($domain !== null) {
            $this->assertTlsRetained($config, $domain);
        }

        $this->assertOnlyOwnedFieldsChanged($adaptedBase, $config, $merged->location);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function pinAdmin(array $config): array
    {
        $existing = $config['admin'] ?? null;
        if (is_array($existing) && isset($existing['listen']) && is_string($existing['listen'])) {
            $requested = $existing['listen'];
            if ($requested !== $this->adminListen) {
                // The operator's Caddyfile tried to own the admin listen. The framework owns it — an
                // override is how the admin API gets accidentally exposed. Fail closed.
                throw EdgeBaseConfigException::adminNotLoopback($requested);
            }
        }

        // Canonical admin block, identical to the from-scratch generator's, so both edge paths bind
        // the admin API the same way.
        $config['admin'] = [
            'listen' => $this->adminListen,
            'enforce_origin' => false,
        ];

        return $config;
    }

    /** @param array<string, mixed> $config */
    private function assertNoPrivilegedListeners(array $config): void
    {
        $servers = $config['apps']['http']['servers'] ?? [];
        if (!is_array($servers)) {
            return;
        }

        foreach ($servers as $server) {
            $listens = is_array($server) ? ($server['listen'] ?? []) : [];
            if (!is_array($listens)) {
                continue;
            }

            foreach ($listens as $listen) {
                if (!is_string($listen)) {
                    continue;
                }
                $port = $this->portOf($listen);
                if ($port !== null && $port < 1024 && !in_array($port, self::ALLOWED_PRIVILEGED_PORTS, true)) {
                    throw EdgeBaseConfigException::privilegedListener((string) $port);
                }
            }
        }
    }

    private function portOf(string $listen): ?int
    {
        // Strip a trailing protocol suffix (e.g. ":443/udp") then take the port after the last colon.
        $listen = explode('/', $listen)[0];
        $colon = strrpos($listen, ':');
        if ($colon === false) {
            return null;
        }

        $portPart = substr($listen, $colon + 1);
        if ($portPart === '' || !ctype_digit($portPart)) {
            return null;
        }

        return (int) $portPart;
    }

    /** @param array<string, mixed> $config */
    private function assertTlsRetained(array $config, string $domain): void
    {
        $policies = $config['apps']['tls']['automation']['policies'] ?? null;
        if (is_array($policies)) {
            foreach ($policies as $policy) {
                if (!is_array($policy)) {
                    continue;
                }
                $subjects = $policy['subjects'] ?? null;
                if (!is_array($subjects) || $subjects === []) {
                    return; // catch-all policy covers the domain
                }
                if (in_array($domain, $subjects, true)) {
                    return; // explicit subject
                }
            }
        }

        // No explicit/catch-all TLS policy: acceptable only if automatic HTTPS is on AND the domain is
        // a host matcher somewhere (Caddy then auto-manages the cert).
        if (!$this->automaticHttpsDisabled($config) && $this->domainIsHostMatcher($config, $domain)) {
            return;
        }

        throw EdgeBaseConfigException::tlsDropped($domain);
    }

    /** @param array<string, mixed> $config */
    private function automaticHttpsDisabled(array $config): bool
    {
        $servers = $config['apps']['http']['servers'] ?? [];
        if (!is_array($servers)) {
            return false;
        }

        foreach ($servers as $server) {
            $auto = is_array($server) ? ($server['automatic_https'] ?? null) : null;
            if (is_array($auto) && ($auto['disable'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    private function domainIsHostMatcher(array $config, string $domain): bool
    {
        return str_contains(
            json_encode($config['apps']['http'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            json_encode($domain, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Defense in depth: the ONLY paths that may differ between the adapted base and the final config
     * are the app proxy handler (its upstreams / load-balancing, or the whole inserted handler), the
     * TLS automation policies, and the admin block. Anything else means the merge touched operator
     * config it does not own.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $final
     */
    private function assertOnlyOwnedFieldsChanged(array $base, array $final, AppProxyLocation $location): void
    {
        $allowedPrefixes = [
            $location->handlerPath,
            ['apps', 'tls'],
            ['admin'],
        ];

        $diffs = [];
        $this->collectDiffPaths($base, $final, [], $diffs);

        foreach ($diffs as $path) {
            if (!$this->isUnderAllowedPrefix($path, $allowedPrefixes)) {
                throw EdgeBaseConfigException::unexpectedMutation($this->pathToString($path));
            }
        }
    }

    /**
     * @param mixed            $base
     * @param mixed            $final
     * @param list<string|int> $prefix
     * @param list<list<string|int>> $diffs out-param
     */
    private function collectDiffPaths(mixed $base, mixed $final, array $prefix, array &$diffs): void
    {
        if (is_array($base) && is_array($final)) {
            $keys = array_unique([...array_keys($base), ...array_keys($final)]);
            foreach ($keys as $key) {
                $inBase = array_key_exists($key, $base);
                $inFinal = array_key_exists($key, $final);
                if (!$inBase || !$inFinal) {
                    $diffs[] = [...$prefix, $key];
                    continue;
                }
                $this->collectDiffPaths($base[$key], $final[$key], [...$prefix, $key], $diffs);
            }

            return;
        }

        if ($base !== $final) {
            $diffs[] = $prefix;
        }
    }

    /**
     * @param list<string|int>        $path
     * @param list<list<string|int>>  $allowedPrefixes
     */
    private function isUnderAllowedPrefix(array $path, array $allowedPrefixes): bool
    {
        foreach ($allowedPrefixes as $prefix) {
            if (\count($prefix) > \count($path)) {
                continue;
            }
            $match = true;
            foreach ($prefix as $i => $segment) {
                if (($path[$i] ?? null) !== $segment) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string|int> $path */
    private function pathToString(array $path): string
    {
        return implode('.', array_map(static fn ($p): string => (string) $p, $path));
    }
}
