<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class CreateTransactionRequest
{
    /**
     * @param TransactionItemRequest[]  $items
     * @param array<string, mixed>|null $customData Attached to the transaction as Paddle custom_data
     *                                              (echoed back on webhooks — e.g. submission_id/entry_id).
     */
    public function __construct(
        public readonly PaddleCustomerId $customerId,
        public readonly array            $items,
        public readonly ?string          $currencyCode = null,
        public readonly ?array           $customData   = null,
    ) {}
}
