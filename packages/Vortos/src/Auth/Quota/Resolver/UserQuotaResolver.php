<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;

final class UserQuotaResolver implements QuotaSubjectResolverInterface
{
    public function bucket(): string
    {
        return 'user';
    }

    public function resolve(UserIdentityInterface $identity): ?string
    {
        return $identity->id();
    }
}
