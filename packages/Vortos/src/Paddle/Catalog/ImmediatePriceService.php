<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Entities\Shared\Interval;
use Paddle\SDK\Entities\Shared\Money as SdkMoney;
use Paddle\SDK\Entities\Shared\Status;
use Paddle\SDK\Entities\Shared\TimePeriod;
use Paddle\SDK\Resources\Prices\Operations\CreatePrice;
use Paddle\SDK\Resources\Prices\Operations\UpdatePrice;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Operation\UpdatePriceRequest;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class ImmediatePriceService implements ImmediatePriceServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreatePriceRequest $request): PaddlePriceId
    {
        $undef = new \Paddle\SDK\Undefined();

        $billingCycle = $request->billingInterval !== null && $request->billingFrequency !== null
            ? new TimePeriod(
                Interval::from($request->billingInterval->value),
                $request->billingFrequency,
            )
            : $undef;

        $sdkPrice = $this->client->call(
            fn() => $this->client->sdk()->prices->create(
                new CreatePrice(
                    description: $request->description,
                    productId:   $request->productId->value,
                    unitPrice:   new SdkMoney(
                        $request->unitPrice->toAmountString(),
                        CurrencyCode::from($request->unitPrice->currencyCode),
                    ),
                    name:         $request->name ?? $undef,
                    billingCycle: $billingCycle,
                )
            )
        );

        return PaddlePriceId::of($sdkPrice->id);
    }

    public function get(PaddlePriceId $id): Price
    {
        $sdkPrice = $this->client->call(
            fn() => $this->client->sdk()->prices->get($id->value)
        );

        return Price::fromSdk($sdkPrice);
    }

    public function update(PaddlePriceId $id, UpdatePriceRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $unitPrice = $request->unitPrice !== null
            ? new SdkMoney(
                $request->unitPrice->toAmountString(),
                CurrencyCode::from($request->unitPrice->currencyCode),
            )
            : $undef;

        $this->client->call(
            fn() => $this->client->sdk()->prices->update(
                $id->value,
                new UpdatePrice(
                    description: $request->description ?? $undef,
                    name:        $request->name ?? $undef,
                    unitPrice:   $unitPrice,
                )
            )
        );
    }

    public function archive(PaddlePriceId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->prices->update(
                $id->value,
                new UpdatePrice(status: Status::Archived())
            )
        );
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->prices->list()
        );

        return array_map(
            fn($sdkPrice) => Price::fromSdk($sdkPrice),
            iterator_to_array($collection)
        );
    }
}
