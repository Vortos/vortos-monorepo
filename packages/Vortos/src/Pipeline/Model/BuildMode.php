<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

enum BuildMode: string
{
    case Native = 'native';
    case BuildxQemu = 'buildx-qemu';
}
