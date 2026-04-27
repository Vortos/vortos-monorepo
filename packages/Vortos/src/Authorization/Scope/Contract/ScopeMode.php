<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope\Contract;

enum ScopeMode
{
    case All; // Must have permission in ALL scopes
    case Any; // Must have permission in ANY scope
}
