<?php

declare(strict_types=1);

namespace Vortos\Push\Contract;

enum WebPushDeliveryStatus: string
{
    /** Accepted by the push service (2xx). */
    case Delivered = 'delivered';

    /** The subscription is dead (404/410) — the caller should stop using it. */
    case Gone = 'gone';

    /** Transient failure (timeout, 5xx, 429) — safe to retry later. */
    case Failed = 'failed';
}
