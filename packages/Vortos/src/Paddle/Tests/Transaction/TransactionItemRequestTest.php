<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class TransactionItemRequestTest extends TestCase
{
    public function test_catalog_line_references_a_price(): void
    {
        $item = TransactionItemRequest::catalog(PaddlePriceId::of('pri_123'), 3);

        $this->assertFalse($item->isNonCatalog());
        $this->assertSame('pri_123', $item->priceId->value);
        $this->assertSame(3, $item->quantity);
        $this->assertNull($item->unitPrice);
    }

    public function test_historical_positional_constructor_still_builds_a_catalog_line(): void
    {
        $item = new TransactionItemRequest(PaddlePriceId::of('pri_legacy'), 1);

        $this->assertFalse($item->isNonCatalog());
        $this->assertSame('pri_legacy', $item->priceId->value);
    }

    public function test_non_catalog_line_carries_an_inline_price_on_a_product(): void
    {
        $item = TransactionItemRequest::nonCatalog(
            productId:   'pro_reg',
            unitPrice:   new Money(6000, 'USD'),
            quantity:    2,
            description: 'Registration fee',
        );

        $this->assertTrue($item->isNonCatalog());
        $this->assertNull($item->priceId);
        $this->assertSame('pro_reg', $item->productId);
        $this->assertSame(6000, $item->unitPrice->amount);
        $this->assertSame('USD', $item->unitPrice->currencyCode);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('Registration fee', $item->description);
    }

    public function test_rejects_a_line_that_is_neither_catalog_nor_non_catalog(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // No priceId and no unitPrice/productId — ambiguous, must be rejected.
        new TransactionItemRequest(quantity: 1);
    }
}
