<?php
declare(strict_types=1);

namespace Vortos\Auth\TokenFreshness;

use Vortos\Auth\Contract\TokenFreshnessGuardInterface;

final class CompositeTokenFreshnessGuard implements TokenFreshnessGuardInterface
{
    /** @var list<TokenFreshnessGuardInterface> */
    private array $guards;

    public function __construct(TokenFreshnessGuardInterface ...$guards)
    {
        $this->guards = $guards;
    }

    public function check(string $userId, int $authzVersion, int $issuedAt): ?string
    {
        foreach ($this->guards as $guard) {
            $reason = $guard->check($userId, $authzVersion, $issuedAt);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
