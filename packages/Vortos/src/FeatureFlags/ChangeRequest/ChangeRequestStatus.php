<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

enum ChangeRequestStatus: string
{
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Applied   = 'applied';
    case Expired   = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending  => in_array($next, [self::Approved, self::Rejected, self::Cancelled, self::Expired], true),
            self::Approved => in_array($next, [self::Applied, self::Cancelled, self::Expired], true),
            default        => false,
        };
    }
}
