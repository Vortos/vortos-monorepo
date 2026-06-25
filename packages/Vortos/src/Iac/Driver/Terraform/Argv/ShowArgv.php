<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform\Argv;

final class ShowArgv
{
    /** @return list<string> */
    public static function build(string $binary, string $planFile): array
    {
        return [
            $binary,
            'show',
            '-json',
            '-no-color',
            $planFile,
        ];
    }
}
