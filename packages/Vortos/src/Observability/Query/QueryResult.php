<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

final readonly class QueryResult
{
    public function __construct(
        public float $value,
        public int $sampleCount,
        public \DateTimeImmutable $at,
    ) {
        if ($this->sampleCount < 0) {
            throw new \InvalidArgumentException('QueryResult sampleCount must be >= 0.');
        }
    }

    public static function empty(\DateTimeImmutable $at): self
    {
        return new self(\NAN, 0, $at);
    }

    public function isEmpty(): bool
    {
        return $this->sampleCount === 0;
    }
}
