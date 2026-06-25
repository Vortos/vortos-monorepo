<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\QuotaSubjectProvenance;

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

    public function requiresAuthentication(): bool
    {
        return true;
    }

    public function provenance(): QuotaSubjectProvenance
    {
        return QuotaSubjectProvenance::ServerVerified;
    }
}
