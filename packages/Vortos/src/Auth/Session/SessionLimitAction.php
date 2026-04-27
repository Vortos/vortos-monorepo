<?php
declare(strict_types=1);

namespace Vortos\Auth\Session;

enum SessionLimitAction
{
    case InvalidateOldest; // Kick oldest session, allow new login
    case RejectNew;        // Reject new login, keep existing sessions
}
