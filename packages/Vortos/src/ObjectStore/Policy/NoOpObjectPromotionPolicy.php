<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Policy;

use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;

final class NoOpObjectPromotionPolicy implements ObjectPromotionPolicyInterface
{
    public function assertCanPromote(PromoteObjectRequest $request): void
    {
    }
}
