<?php
declare(strict_types=1);

namespace Vortos\Auth\TokenFreshness;

use Psr\Log\LoggerInterface;
use Vortos\Auth\Contract\TokenFreshnessGuardInterface;

final class MinIatGuard implements TokenFreshnessGuardInterface
{
    public function __construct(
        private MinIatStoreInterface $store,
        private ?LoggerInterface $logger = null,
    ) {}

    public function check(string $userId, int $authzVersion, int $issuedAt): ?string
    {
        try {
            $minIat = $this->store->get();
        } catch (\Throwable $e) {
            $this->logger?->error('token_freshness.min_iat_unavailable', ['exception' => $e::class]);
            return 'Token freshness check unavailable.';
        }

        if ($minIat !== null && $issuedAt < $minIat) {
            return 'Token issued before global revocation epoch.';
        }

        return null;
    }
}
