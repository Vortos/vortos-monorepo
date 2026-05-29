<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Exception;

final class OverRefundException extends \RuntimeException
{
    public function __construct(
        public readonly string $lineItemId,
        public readonly string $requestedAmount,
        public readonly string $lineItemTotal,
    ) {
        parent::__construct(sprintf(
            'Refund amount %s exceeds line item total %s for item %s.',
            $requestedAmount,
            $lineItemTotal,
            $lineItemId,
        ));
    }
}
