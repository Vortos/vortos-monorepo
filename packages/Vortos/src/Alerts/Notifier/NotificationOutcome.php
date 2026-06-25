<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

enum NotificationOutcome: string
{
    case Delivered = 'delivered';
    case Suppressed = 'suppressed';
    case Deduped = 'deduped';
    case RateLimited = 'rate_limited';
    case Failed = 'failed';

    public function isSuccess(): bool
    {
        return $this === self::Delivered || $this === self::Suppressed || $this === self::Deduped;
    }
}
