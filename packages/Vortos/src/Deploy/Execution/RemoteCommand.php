<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

final readonly class RemoteCommand
{
    /** @param list<string> $argv */
    public function __construct(
        public array $argv,
        public ?string $stdin = null,
        public ?string $workingDir = null,
    ) {
        if ($argv === []) {
            throw new \InvalidArgumentException('Remote command argv must not be empty.');
        }
    }
}
