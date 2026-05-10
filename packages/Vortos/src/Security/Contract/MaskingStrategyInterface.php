<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

interface MaskingStrategyInterface
{
    /**
     * Returns a masked version of $value suitable for log output.
     * Must never return the original sensitive value.
     */
    public function mask(string $value): string;
}
