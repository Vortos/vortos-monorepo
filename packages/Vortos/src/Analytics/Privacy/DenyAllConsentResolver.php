<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

use Vortos\Analytics\Event\DistinctId;

/**
 * The framework default: denies consent for everyone. This is what makes "the base
 * install sends nothing" a guarantee rather than a convention — even with a real
 * driver wired, nothing leaves the process until the app supplies its own
 * {@see ConsentResolverInterface}.
 */
final class DenyAllConsentResolver implements ConsentResolverInterface
{
    public function resolve(DistinctId $distinctId): ConsentDecision
    {
        return ConsentDecision::Denied;
    }
}
