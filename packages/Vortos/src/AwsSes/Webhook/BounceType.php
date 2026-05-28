<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

enum BounceType: string
{
    /** Hard bounce — address does not exist. Auto-suppressed permanently. */
    case Permanent = 'Permanent';

    /** Soft bounce — temporary delivery failure (mailbox full, server down). Not auto-suppressed. */
    case Transient = 'Transient';

    case Undetermined = 'Undetermined';
}
