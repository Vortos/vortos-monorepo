<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

final class WebhookReplayException extends PaddleWebhookException
{
    public static function timestampExpired(int $ageSeconds, int $windowSeconds): self
    {
        return new self(sprintf(
            'Paddle webhook timestamp is %d seconds old, exceeds the %d second replay window.',
            $ageSeconds,
            $windowSeconds,
        ));
    }
}
