<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum AdjustmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Reversed        = 'reversed';
}
