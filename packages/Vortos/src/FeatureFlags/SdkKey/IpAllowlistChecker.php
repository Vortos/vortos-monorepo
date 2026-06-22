<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\SdkKey;

final class IpAllowlistChecker
{
    /**
     * Returns true if $ip is covered by any CIDR in $cidrs.
     * Supports both IPv4 (e.g. 192.168.1.0/24) and IPv6 (e.g. ::1/128).
     * A plain IP without prefix length is treated as /32 (IPv4) or /128 (IPv6).
     */
    public function isAllowed(string $ip, array $cidrs): bool
    {
        $ipBin = inet_pton($ip);

        if ($ipBin === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if ($this->matchesCidr($ipBin, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function matchesCidr(string $ipBin, string $cidr): bool
    {
        if (str_contains($cidr, '/')) {
            [$network, $prefixLen] = explode('/', $cidr, 2);
            $prefixLen = (int) $prefixLen;
        } else {
            $network   = $cidr;
            $prefixLen = strlen($ipBin) === 4 ? 32 : 128;
        }

        $networkBin = inet_pton($network);

        if ($networkBin === false || strlen($networkBin) !== strlen($ipBin)) {
            return false;
        }

        $byteLen  = strlen($ipBin);
        $fullBytes = intdiv($prefixLen, 8);
        $remBits   = $prefixLen % 8;

        // Compare full bytes.
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        // Compare the partial byte (if any).
        if ($remBits > 0 && $fullBytes < $byteLen) {
            $mask = 0xFF & (0xFF << (8 - $remBits));
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($networkBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
