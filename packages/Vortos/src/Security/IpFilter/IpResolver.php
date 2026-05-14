<?php

declare(strict_types=1);

namespace Vortos\Security\IpFilter;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the real client IP address, respecting trusted proxy chains.
 *
 * When a request passes through a reverse proxy (nginx, load balancer,
 * Cloudflare), the proxy appends the original IP to X-Forwarded-For.
 * We only trust this header when the connecting IP is in $trustedProxies.
 *
 * Also checks CIDR membership for allowlist/denylist evaluation.
 */
final class IpResolver
{
    /** @param list<string> $trustedProxies */
    public function __construct(
        private readonly array $trustedProxies,
    ) {}

    /**
     * Returns the client IP. When the connecting IP is a trusted proxy,
     * walks X-Forwarded-For from right to left and returns the rightmost
     * IP that is not a trusted proxy — this is the first hop we don't control
     * and therefore the real client.
     *
     * Using the leftmost entry is unsafe: an attacker can prepend arbitrary IPs.
     */
    public function resolve(Request $request): string
    {
        $connectingIp = $request->server->get('REMOTE_ADDR', '127.0.0.1');

        if (!$this->isTrustedProxy($connectingIp)) {
            return $connectingIp;
        }

        $forwarded = $request->headers->get('X-Forwarded-For', '');
        if ($forwarded === '') {
            return $connectingIp;
        }

        // Walk from rightmost (most recently added) and skip trusted proxies.
        // The first non-trusted IP encountered is the real client.
        $ips = array_map('trim', explode(',', $forwarded));
        foreach (array_reverse($ips) as $ip) {
            if (!$this->isTrustedProxy($ip)) {
                return $ip;
            }
        }

        // Every IP in XFF is a trusted proxy — fall back to connecting IP
        return $connectingIp;
    }

    /**
     * Returns true if $ip is in any of the given CIDR ranges/exact IPs.
     *
     * @param list<string> $cidrs
     */
    public function matchesCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private function isTrustedProxy(string $ip): bool
    {
        return $this->matchesCidr($ip, $this->trustedProxies);
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        // Handle IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong      = ip2long($ip);
            $networkLong = ip2long($network);
            if ($ipLong === false || $networkLong === false) {
                return false;
            }
            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
            return ($ipLong & $mask) === ($networkLong & $mask);
        }

        // Handle IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin      = inet_pton($ip);
            $networkBin = inet_pton($network);
            if ($ipBin === false || $networkBin === false) {
                return false;
            }
            $bytesToCheck  = (int) floor($prefix / 8);
            $bitsRemaining = $prefix % 8;

            // Compare full bytes
            if (substr($ipBin, 0, $bytesToCheck) !== substr($networkBin, 0, $bytesToCheck)) {
                return false;
            }

            if ($bitsRemaining === 0) {
                return true;
            }

            $mask    = 0xFF & (0xFF << (8 - $bitsRemaining));
            $ipByte  = ord($ipBin[$bytesToCheck]);
            $netByte = ord($networkBin[$bytesToCheck]);
            return ($ipByte & $mask) === ($netByte & $mask);
        }

        return false;
    }
}
