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
 *
 * ── $name is not $description ─────────────────────────────────────────────
 * Paddle shows the inline price's `name` on the checkout, and falls back to the
 * *product's* name when it is absent. `description` never reaches the payer at
 * all — it is internal. Sending only a description therefore prints the shared
 * product name on every line, so a two-line order reads as the same item twice.
 * Both are carried, and ::nonCatalog() defaults the name to the description so
 * a caller cannot accidentally set only the invisible one.
 */
final class TransactionItemRequest
{
    public function __construct(
        public readonly ?PaddlePriceId $priceId = null,
        public readonly int            $quantity = 1,
        public readonly ?Money         $unitPrice = null,
        public readonly ?string        $productId = null,
        public readonly ?string        $description = null,
        public readonly ?string        $name = null,
        /**
         * Pins the payer-facing quantity selector to exactly this quantity.
         *
         * Paddle's default bounds let the payer raise the quantity or remove
         * the line from the checkout. On an inline price that is nearly always
         * wrong: the amount was computed server-side for a specific thing being
         * bought, and a payer who deletes one line of a priced order settles a
         * total nobody authorised. Defaults to locked; pass false for a line
         * that genuinely is a shopping-cart quantity.
         */
        public readonly bool           $fixedQuantity = true,
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
        string  $productId,
        Money   $unitPrice,
        int     $quantity = 1,
        string  $description = 'Registration payment',
        ?string $name = null,
        bool    $fixedQuantity = true,
    ): self {
        return new self(
            quantity:      $quantity,
            unitPrice:     $unitPrice,
            productId:     $productId,
            description:   $description,
            // The description is what every existing caller already passes as
            // the human label, so defaulting to it makes the payer see the
            // right thing without each of them being changed.
            name:          $name ?? $description,
            fixedQuantity: $fixedQuantity,
        );
    }

    public function isNonCatalog(): bool
    {
        return $this->priceId === null;
    }
}
