<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;

interface ObjectPromotionPolicyInterface
{
    /**
     * Called immediately before a temporary direct-upload object is promoted.
     *
     * Implementations can reject promotion for malware scanning, quarantine,
     * data-residency, or business-policy reasons.
     */
    public function assertCanPromote(PromoteObjectRequest $request): void;
}
