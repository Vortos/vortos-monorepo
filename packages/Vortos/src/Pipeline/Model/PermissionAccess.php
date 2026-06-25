<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

enum PermissionAccess: string
{
    case Read = 'read';
    case Write = 'write';
    case None = 'none';
}
