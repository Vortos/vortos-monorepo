<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A Docker network the deploy creates if it is missing, and whether its one-shots join it.
 *
 * ## Why the deploy owns these
 *
 * The colors and the edge declare their networks `external`, so something has to create them
 * before either starts, and a network created by hand is a step a rebuilt box forgets. The deploy
 * script runs first on every release, so it makes each one exist — idempotently, and never altering
 * one that is already there.
 *
 * ## Internal networks
 *
 * `internal` networks have no route off the host. They carry control traffic — the Docker API
 * proxy, the edge's admin API — that nothing should be able to reach from outside, and that has no
 * reason to reach out. A container that also needs the internet joins a second, ordinary network.
 *
 * ## Joined by the deploy
 *
 * The deploy one-shots run migrations (the app network), drive Docker (the proxy's network) and
 * reconfigure the edge (its admin network). Each network they must reach says so here, so the
 * one-shot's `--network` flags come from the same declaration that creates the network.
 *
 * ## A declared subnet
 *
 * Docker picks a free address range when it creates a network, so the same declaration lands on a
 * different range on a rebuilt box, or after the network is recreated. Anything that names the
 * network by address — an app trusting its reverse proxy's network to forward the client's IP — then
 * silently trusts the wrong range. A declared subnet fixes the range: the deploy creates the network
 * with it, and refuses to release onto an existing network whose range differs, because a mismatch
 * there is exactly the drift that would make the app trust the wrong peers. Only private (RFC 1918)
 * IPv4 ranges are accepted: these are container networks on one host, never routable addresses.
 */
final readonly class DockerNetwork
{
    public function __construct(
        public string $name,
        public bool $internal,
        public bool $joinedByDeploy,
        public ?string $subnet = null,
    ) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Docker network name "%s" is not valid.', $name));
        }

        if ($subnet !== null) {
            self::assertPrivateSubnet($name, $subnet);
        }
    }

    /** An internal network the deploy one-shots join (the Docker API proxy's, the edge admin's). */
    public static function control(string $name, ?string $subnet = null): self
    {
        return new self($name, internal: true, joinedByDeploy: true, subnet: $subnet);
    }

    /** An ordinary network only services join (the edge's serving network). */
    public static function serving(string $name, ?string $subnet = null): self
    {
        return new self($name, internal: false, joinedByDeploy: false, subnet: $subnet);
    }

    /** Whether this network's declared range shares any address with another's. */
    public function overlaps(self $other): bool
    {
        if ($this->subnet === null || $other->subnet === null) {
            return false;
        }

        [$a, $aBits] = self::range($this->subnet);
        [$b, $bBits] = self::range($other->subnet);
        $shorter = min($aBits, $bBits);
        $mask = $shorter === 0 ? 0 : (0xFFFFFFFF << (32 - $shorter)) & 0xFFFFFFFF;

        return ($a & $mask) === ($b & $mask);
    }

    /** @return array{name: string, internal: bool, joined_by_deploy: bool, subnet: string|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'internal' => $this->internal,
            'joined_by_deploy' => $this->joinedByDeploy,
            'subnet' => $this->subnet,
        ];
    }

    private static function assertPrivateSubnet(string $name, string $subnet): void
    {
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $subnet, $m) !== 1
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || (int) $m[2] > 28
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Network "%s": subnet "%s" must be an IPv4 CIDR no narrower than /28, e.g. 172.22.0.0/16.',
                $name,
                $subnet,
            ));
        }

        [$network, $bits] = self::range($subnet);
        $hostBits = $bits === 0 ? 0xFFFFFFFF : (~(0xFFFFFFFF << (32 - $bits))) & 0xFFFFFFFF;
        if (($network & $hostBits) !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'Network "%s": subnet "%s" has host bits set; write the network address (%s/%d).',
                $name,
                $subnet,
                long2ip($network & ~$hostBits & 0xFFFFFFFF),
                $bits,
            ));
        }

        // RFC 1918: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 — the range must sit wholly inside one.
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16]] as [$base, $baseBits]) {
            $baseMask = (0xFFFFFFFF << (32 - $baseBits)) & 0xFFFFFFFF;
            if ($bits >= $baseBits && ($network & $baseMask) === ip2long($base)) {
                return;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Network "%s": subnet "%s" is not a private (RFC 1918) range.',
            $name,
            $subnet,
        ));
    }

    /** @return array{0: int, 1: int} the network address as an unsigned 32-bit int, and the prefix length */
    private static function range(string $subnet): array
    {
        [$ip, $bits] = explode('/', $subnet, 2);

        return [(int) ip2long($ip), (int) $bits];
    }
}
