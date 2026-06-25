<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class SmokeSpec
{
    /** @param list<SmokeCheck> $checks */
    public function __construct(
        public array $checks = [],
    ) {}
}
