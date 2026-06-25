<?php

declare(strict_types=1);

namespace Vortos\Security\Doctor;

use Vortos\Foundation\Doctor\Attribute\AsDoctor;
use Vortos\Foundation\Doctor\Contract\DoctorCheckInterface;
use Vortos\Foundation\Doctor\DoctorResult;

#[AsDoctor]
final class TrustedProxyDoctorCheck implements DoctorCheckInterface
{
    /** @param list<string> $trustedProxies */
    public function __construct(
        private readonly array $trustedProxies,
        private readonly bool $hasIpRateLimits,
    ) {}

    public function name(): string
    {
        return 'security.trusted-proxies';
    }

    public function run(): DoctorResult
    {
        if ($this->trustedProxies === [] && $this->hasIpRateLimits) {
            return DoctorResult::error(
                $this->name(),
                'IP-scoped rate limits are configured but trusted_proxies is empty — behind a reverse proxy, '
                . 'all clients share one rate-limit bucket (the proxy IP), enabling login DoS',
                'Set trusted_proxies in config/security.php to your reverse proxy IPs (e.g. ["127.0.0.1"]). '
                . 'For Caddy on the same host, use ["127.0.0.1", "::1"].',
            );
        }

        if ($this->trustedProxies === []) {
            return DoctorResult::warning(
                $this->name(),
                'trusted_proxies is empty — getClientIp() returns REMOTE_ADDR, which behind a reverse proxy '
                . 'is the proxy IP, not the real client',
                'Set trusted_proxies in config/security.php to your reverse proxy IPs.',
            );
        }

        foreach ($this->trustedProxies as $entry) {
            if ($entry === '*' || $entry === 'REMOTE_ADDR') {
                return DoctorResult::error(
                    $this->name(),
                    sprintf('Wildcard trusted proxy "%s" trusts all connecting IPs, enabling X-Forwarded-For spoofing', $entry),
                    'Replace with your actual proxy IPs/CIDRs.',
                );
            }

            if (str_contains($entry, '/')) {
                $prefix = (int) explode('/', $entry, 2)[1];
                $network = explode('/', $entry, 2)[0];
                $isV6 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
                $minPrefix = $isV6 ? 16 : 8;

                if ($prefix < $minPrefix) {
                    return DoctorResult::error(
                        $this->name(),
                        sprintf('Overly broad CIDR "%s" in trusted_proxies (prefix /%d < minimum /%d) — '
                            . 'enables X-Forwarded-For spoofing', $entry, $prefix, $minPrefix),
                        'Narrow the CIDR to your actual proxy network.',
                    );
                }
            }
        }

        return DoctorResult::ok(
            $this->name(),
            sprintf('trusted_proxies configured: %d entries', count($this->trustedProxies)),
        );
    }
}
