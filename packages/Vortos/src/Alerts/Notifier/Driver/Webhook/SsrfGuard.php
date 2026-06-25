<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Webhook;

/**
 * SSRF-hardened destination validation for the generic webhook driver (§4.2) —
 * the one place this check lives, so every user-configured webhook URL is safe by
 * construction. Blocks: non-https schemes (outside dev), every private/link-local/
 * loopback/cloud-metadata range (incl. `169.254.169.254`), and IPv6 ULA/link-local.
 * Redirect-following is disabled at the transport ({@see \Vortos\Alerts\Notifier\Driver\GuzzleNotifierTransport}),
 * not here — this guard validates the declared destination, not a post-redirect hop.
 */
final class SsrfGuard
{
    /** CIDR-style deny list: [network, prefixLength]. IPv4 and IPv6 both covered. */
    private const DENIED_RANGES = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],     // shared address space (CGNAT)
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],    // link-local, includes the cloud metadata IP 169.254.169.254
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],      // IETF protocol assignments
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],     // benchmarking
        ['224.0.0.0', 4],       // multicast
        ['::1', 128],
        ['::', 128],
        ['::ffff:0:0', 96],     // IPv4-mapped — caught by the IPv4 check on the mapped address too
        ['fc00::', 7],          // unique local address (ULA)
        ['fe80::', 10],         // link-local
        ['ff00::', 8],          // multicast
    ];

    /** @param (\Closure(string):list<string>)|null $resolver */
    public function __construct(
        private readonly bool $allowInsecureScheme = false,
        private readonly ?\Closure $resolver = null,
    ) {}

    /** @throws SsrfViolationException */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfViolationException("Malformed webhook URL: '{$url}'.");
        }

        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = $this->allowInsecureScheme ? ['http', 'https'] : ['https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new SsrfViolationException("Webhook scheme '{$scheme}' is not allowed; only " . implode(', ', $allowedSchemes) . ' is permitted.');
        }

        $host = $parts['host'];
        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isDenied($ip)) {
                throw new SsrfViolationException("Webhook host '{$host}' resolves to denied address '{$ip}'.");
            }
        }
    }

    /** @return list<string> */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    private function isDenied(string $ip): bool
    {
        foreach (self::DENIED_RANGES as [$network, $prefix]) {
            if ($this->inRange($ip, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $ip, string $network, int $prefix): bool
    {
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $bits)) & 0xFF);
        $ipByte = substr($ipBin, $bytes, 1);
        $netByte = substr($netBin, $bytes, 1);

        return (chr(ord($ipByte) & ord($mask))) === (chr(ord($netByte) & ord($mask)));
    }
}
