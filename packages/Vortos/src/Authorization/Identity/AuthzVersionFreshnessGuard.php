<?php
declare(strict_types=1);

namespace Vortos\Authorization\Identity;

use Vortos\Auth\Contract\TokenFreshnessGuardInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;

final class AuthzVersionFreshnessGuard implements TokenFreshnessGuardInterface
{
    public function __construct(
        private AuthorizationVersionStoreInterface $versionStore,
    ) {}

    public function check(string $userId, int $authzVersion, int $issuedAt): ?string
    {
        $currentVersion = $this->versionStore->versionForUser($userId);

        if ($authzVersion < $currentVersion) {
            return 'Authorization version is stale. Re-authenticate to get updated permissions.';
        }

        return null;
    }
}
