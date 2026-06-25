<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform\Argv;

final class ApplyArgv
{
    /** @return list<string> */
    public static function build(string $binary, string $planFile): array
    {
        return [
            $binary,
            'apply',
            '-input=false',
            '-no-color',
            '-auto-approve',
            $planFile,
        ];
    }
}
