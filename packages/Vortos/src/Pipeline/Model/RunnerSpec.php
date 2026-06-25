<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class RunnerSpec
{
    public function __construct(
        public string $label = 'ubuntu-latest',
        public ?string $archHint = null,
    ) {
        if ($label === '') {
            throw new \InvalidArgumentException('Runner label must be non-empty.');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        $data = ['label' => $this->label];

        if ($this->archHint !== null) {
            $data['arch_hint'] = $this->archHint;
        }

        return $data;
    }
}
