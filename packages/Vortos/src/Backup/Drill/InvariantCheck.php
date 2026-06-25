<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

interface InvariantCheck
{
    public function name(): string;

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function check(array $connectionParams): InvariantResult;
}
