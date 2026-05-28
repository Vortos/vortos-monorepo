<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class RateLimitExceededException extends \RuntimeException
{
    public static function afterTimeout(int $waitMs): self
    {
        return new self(sprintf(
            'SES rate limit exceeded: no token available after waiting %dms. Increase max_send_rate or reduce send volume.',
            $waitMs,
        ));
    }
}
