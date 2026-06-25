<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

use Vortos\Analytics\Event\DistinctId;

/**
 * The app supplies the real consent source (its own consent record / cookie-consent
 * state). The framework default is {@see DenyAllConsentResolver} — nothing is sent
 * until the app wires a real resolver.
 */
interface ConsentResolverInterface
{
    public function resolve(DistinctId $distinctId): ConsentDecision;
}
