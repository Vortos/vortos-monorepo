<?php

declare(strict_types=1);

namespace Vortos\Release\Manifest;

enum Arch: string
{
    case Amd64 = 'linux/amd64';
    case Arm64 = 'linux/arm64';
}
