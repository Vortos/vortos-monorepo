<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Matrix
{
    /**
     * A matrix axis is either **scalar-valued** (each entry a non-empty string, e.g.
     * `environment: [production, staging]` — referenced as `${{ matrix.environment }}`) or
     * **object-valued** (each entry a non-empty string map, e.g. the monorepo-split axis whose
     * entries carry sub-keys referenced as `${{ matrix.package.local_path }}`). The two forms must
     * never be mixed within one axis: a scalar axis referenced as an object (or vice-versa)
     * resolves to a stringified object and GitHub fails to initialise the matrix job (B8).
     *
     * @param list<string|array<string, string>> $values
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

        $scalarSeen = false;
        $mapSeen = false;
        foreach ($values as $value) {
            if (is_string($value)) {
                if ($value === '') {
                    throw new \InvalidArgumentException('Matrix scalar value must be non-empty.');
                }
                $scalarSeen = true;
                continue;
            }

            if ($value === []) {
                throw new \InvalidArgumentException('Matrix object value must be a non-empty string map.');
            }
            $mapSeen = true;
        }

        if ($scalarSeen && $mapSeen) {
            throw new \InvalidArgumentException(sprintf(
                'Matrix axis "%s" mixes scalar and object values; an axis must be entirely one or the other.',
                $axisName,
            ));
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
