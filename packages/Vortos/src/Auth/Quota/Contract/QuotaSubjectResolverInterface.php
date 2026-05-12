<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;

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
}
