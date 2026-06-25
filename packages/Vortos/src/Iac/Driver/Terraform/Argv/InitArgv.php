<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform\Argv;

final class InitArgv
{
    /** @return list<string> */
    public static function build(string $binary): array
    {
        return [
            $binary,
            'init',
            '-input=false',
            '-no-color',
        ];
    }
}
