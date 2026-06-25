<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Matrix
{
    /**
     * @param list<array<string, string>> $values
     */
    public function __construct(
        public string $axisName,
        public array $values,
        public bool $failFast = false,
    ) {
        if ($axisName === '') {
            throw new \InvalidArgumentException('Matrix axis name must be non-empty.');
        }

        if ($values === []) {
            throw new \InvalidArgumentException('Matrix must contain at least one value set.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'axis_name' => $this->axisName,
            'values' => $this->values,
            'fail_fast' => $this->failFast,
        ];
    }
}
