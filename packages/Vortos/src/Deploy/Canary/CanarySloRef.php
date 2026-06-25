<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class CanarySloRef
{
    public function __construct(
        public string $name,
        public string $indicatorRef,
        public float $objective,
    ) {}
}
