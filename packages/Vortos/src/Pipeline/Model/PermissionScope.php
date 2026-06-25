<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

enum PermissionScope: string
{
    case Contents = 'contents';
    case Packages = 'packages';
    case IdToken = 'id-token';
    case Actions = 'actions';
    case PullRequests = 'pull-requests';
    case Checks = 'checks';
}
