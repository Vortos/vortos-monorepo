<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

/**
 * The only I/O seam in the BetterStack driver — everything above this is
 * unit-testable without a real HTTP call. Implementations must be bounded (hard
 * timeout) and must never throw; a connection failure is reported via a non-2xx-ish
 * sentinel status (0) so {@see BetterStackClient} can classify it uniformly.
 */
interface BetterStackTransportInterface
{
    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?array $jsonBody): array;
}
