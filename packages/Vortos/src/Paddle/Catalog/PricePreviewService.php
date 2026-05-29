<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Paddle\SDK\Entities\PricingPreview\PricePreviewItem as SdkPreviewItem;
use Paddle\SDK\Entities\Shared\AddressPreview;
use Paddle\SDK\Entities\Shared\CountryCode;
use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Resources\PricingPreviews\Operations\PreviewPrice;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Contract\PricePreviewServiceInterface;
use Vortos\Paddle\Catalog\Operation\PricePreviewRequest;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class PricePreviewService implements PricePreviewServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function preview(PricePreviewRequest $request): PricePreviewResult
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkItems = array_map(
            fn($item) => new SdkPreviewItem(
                priceId:  $item->priceId->value,
                quantity: $item->quantity,
            ),
            $request->items,
        );

        $address = $request->countryCode !== null
            ? new AddressPreview(null, CountryCode::from($request->countryCode))
            : $undef;

        $sdkResult = $this->client->call(
            fn() => $this->client->sdk()->pricingPreviews->previewPrices(
                new PreviewPrice(
                    items:       $sdkItems,
                    customerId:  $request->customerId ?? $undef,
                    discountId:  $request->discountId ?? $undef,
                    currencyCode: $request->currencyCode !== null
                                      ? CurrencyCode::from($request->currencyCode)
                                      : $undef,
                    address:     $address,
                )
            )
        );

        $currencyCode = (string) $sdkResult->currencyCode;

        $items = array_map(
            fn($lineItem) => new PricePreviewResultItem(
                priceId:      PaddlePriceId::of($lineItem->price->id),
                quantity:     $lineItem->quantity,
                subtotal:     $lineItem->totals->subtotal,
                tax:          $lineItem->totals->tax,
                total:        $lineItem->totals->total,
                currencyCode: $currencyCode,
            ),
            $sdkResult->details->lineItems,
        );

        return new PricePreviewResult(
            currencyCode: $currencyCode,
            items:        $items,
        );
    }
}
