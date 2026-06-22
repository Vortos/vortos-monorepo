<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

/**
 * SSRF guard for outbound webhook URLs (Block 18).
 *
 * Validates that a URL does not target internal/private networks. DNS-rebinding-aware:
 * resolves the hostname and checks the resolved IP against blocked CIDR ranges.
 *
 * This is **load-bearing security** — every outbound webhook URL MUST pass through this
 * guard before delivery. The guard is also re-checked on redirect (the dispatcher must
 * resolve-then-pin, not follow blindly).
 */
final class SsrfGuard
{
    /** @var list<array{string,int}> CIDR blocks that must never be targeted */
    private const BLOCKED_CIDRS = [
        // IPv4 private / special-use
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],   // link-local
        ['0.0.0.0', 8],
        ['100.64.0.0', 10],    // shared address space (CGNAT)
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // documentation
        ['198.51.100.0', 24],  // documentation
        ['203.0.113.0', 24],   // documentation
        ['224.0.0.0', 4],      // multicast
        ['240.0.0.0', 4],      // reserved
        // Cloud metadata endpoints
        ['169.254.169.254', 32],
    ];

    private const BLOCKED_PORTS = [22, 23, 25, 445, 3306, 5432, 6379, 27017];

    /** Only these schemes are permitted. */
    private const ALLOWED_SCHEMES = ['https'];

    /**
     * Validate a URL is safe for outbound webhook delivery.
     *
     * @return array{safe:bool,reason:?string,resolved_ip:?string}
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return ['safe' => false, 'reason' => 'invalid URL', 'resolved_ip' => null];
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return ['safe' => false, 'reason' => 'only HTTPS is allowed', 'resolved_ip' => null];
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (in_array($port, self::BLOCKED_PORTS, true)) {
            return ['safe' => false, 'reason' => 'blocked port', 'resolved_ip' => null];
        }

        // Resolve DNS to get the actual IP
        $ip = $this->resolveHost($host);
        if ($ip === null) {
            return ['safe' => false, 'reason' => 'DNS resolution failed', 'resolved_ip' => null];
        }

        // Check against blocked CIDRs
        foreach (self::BLOCKED_CIDRS as [$network, $prefix]) {
            if ($this->ipInCidr($ip, $network, $prefix)) {
                return ['safe' => false, 'reason' => 'target resolves to a private/blocked IP', 'resolved_ip' => $ip];
            }
        }

        // IPv6 loopback
        if ($ip === '::1' || str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc00:') || str_starts_with($ip, 'fd00:')) {
            return ['safe' => false, 'reason' => 'target resolves to a private/blocked IP', 'resolved_ip' => $ip];
        }

        return ['safe' => true, 'reason' => null, 'resolved_ip' => $ip];
    }

    /**
     * Validate an already-resolved IP (used on redirect to re-check).
     */
    public function validateIp(string $ip): bool
    {
        foreach (self::BLOCKED_CIDRS as [$network, $prefix]) {
            if ($this->ipInCidr($ip, $network, $prefix)) {
                return false;
            }
        }

        if ($ip === '::1' || str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc00:') || str_starts_with($ip, 'fd00:')) {
            return false;
        }

        return true;
    }

    private function resolveHost(string $host): ?string
    {
        // If it's already an IP, return directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return null;
        }

        // Return the first A record
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                return $record['ip'];
            }
            if (isset($record['ipv6'])) {
                return $record['ipv6'];
            }
        }

        return null;
    }

    private function ipInCidr(string $ip, string $network, int $prefix): bool
    {
        $ipLong      = ip2long($ip);
        $networkLong = ip2long($network);

        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($networkLong & $mask);
    }
}
