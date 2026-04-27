<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\QuotaRule;

/**
 * Defines quota rules for a given identity and quota type.
 * Auto-discovered — just implement this interface.
 */
interface QuotaPolicyInterface
{
    public function getQuota(UserIdentityInterface $identity, string $quota): QuotaRule;
}
