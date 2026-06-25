<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\QuotaSubjectProvenance;

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

    public function requiresAuthentication(): bool
    {
        return false;
    }

    public function provenance(): QuotaSubjectProvenance
    {
        return QuotaSubjectProvenance::ServerVerified;
    }
}
