<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

final readonly class QuerySample
{
    public function __construct(
        public float $value,
        public int $timestampSeconds,
    ) {}
}
