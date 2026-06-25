<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Quota\QuotaSubjectProvenance;

interface QuotaSubjectResolverInterface
{
    /**
     * Low-cardinality bucket name used in headers, logs, traces, metrics, and Redis keys.
     */
    public function bucket(): string;

    /**
     * Returns the stable subject identifier for this quota bucket.
     * Return null when the identity cannot be resolved for this bucket.
     */
    public function resolve(UserIdentityInterface $identity): ?string;

    /**
     * Whether this resolver requires an authenticated identity.
     * Resolvers returning false (e.g. GlobalQuotaResolver) enforce quota on anonymous requests.
     */
    public function requiresAuthentication(): bool;

    /**
     * Declares the provenance of the subject identifier.
     * ServerVerified subjects come from signed token claims (sub) or server-side lookups.
     * ClaimDerived subjects come from user-influenced JWT attrs — these are rejected at runtime.
     */
    public function provenance(): QuotaSubjectProvenance;
}
