<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddlePriceId;

/**
 * A single line on a transaction.
 *
 * Two flavours:
 *   • Catalog     — references an existing Paddle Price by id (the historical shape).
 *   • Non-catalog — an ad-hoc inline price of $unitPrice attached to an existing
 *                   product ($productId). Lets callers charge an exact amount that
 *                   isn't a pre-published catalog price (e.g. a per-registration fee
 *                   derived at runtime) without polluting the price catalog.
 *
 * The historical positional constructor `new TransactionItemRequest($priceId, $qty)`
 * still works unchanged; non-catalog lines are built via ::nonCatalog().
 */
final class TransactionItemRequest
{
    public function __construct(
        public readonly ?PaddlePriceId $priceId = null,
        public readonly int            $quantity = 1,
        public readonly ?Money         $unitPrice = null,
        public readonly ?string        $productId = null,
        public readonly ?string        $description = null,
    ) {
        if ($priceId === null && ($unitPrice === null || $productId === null)) {
            throw new \InvalidArgumentException(
                'TransactionItemRequest requires either a catalog priceId or a non-catalog unitPrice + productId.'
            );
        }
    }

    /** A line that references an existing catalog Price. */
    public static function catalog(PaddlePriceId $priceId, int $quantity = 1): self
    {
        return new self(priceId: $priceId, quantity: $quantity);
    }

    /** An ad-hoc line: an inline price of $unitPrice on the existing product $productId. */
    public static function nonCatalog(
        string $productId,
        Money  $unitPrice,
        int    $quantity = 1,
        string $description = 'Registration payment',
    ): self {
        return new self(
            quantity:    $quantity,
            unitPrice:   $unitPrice,
            productId:   $productId,
            description: $description,
        );
    }

    public function isNonCatalog(): bool
    {
        return $this->priceId === null;
    }
}
