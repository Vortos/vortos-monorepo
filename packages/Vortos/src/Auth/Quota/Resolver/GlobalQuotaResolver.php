<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;

final class GlobalQuotaResolver implements QuotaSubjectResolverInterface
{
    public function bucket(): string
    {
        return 'global';
    }

    public function resolve(UserIdentityInterface $identity): ?string
    {
        return 'global';
    }
}
