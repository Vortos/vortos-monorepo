<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Paddle\SDK\Entities\Discount\DiscountStatus as SdkDiscountStatus;
use Paddle\SDK\Entities\Discount\DiscountType as SdkDiscountType;
use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Resources\Discounts\Operations\CreateDiscount;
use Paddle\SDK\Resources\Discounts\Operations\UpdateDiscount;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\UpdateDiscountRequest;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

final class ImmediateDiscountService implements ImmediateDiscountServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateDiscountRequest $request): PaddleDiscountId
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkDiscount = $this->client->call(
            fn() => $this->client->sdk()->discounts->create(
                new CreateDiscount(
                    amount:             $request->amount,
                    description:        $request->description,
                    type:               SdkDiscountType::from($request->type->value),
                    enabledForCheckout: $request->enabledForCheckout,
                    recur:              $request->recur,
                    currencyCode:       CurrencyCode::from($request->currencyCode),
                    code:               $request->code ?? $undef,
                    usageLimit:         $request->usageLimit ?? $undef,
                    expiresAt:          $request->expiresAt?->format(\DateTimeInterface::RFC3339) ?? $undef,
                )
            )
        );

        return PaddleDiscountId::of($sdkDiscount->id);
    }

    public function get(PaddleDiscountId $id): Discount
    {
        $sdkDiscount = $this->client->call(
            fn() => $this->client->sdk()->discounts->get($id->value)
        );

        return Discount::fromSdk($sdkDiscount);
    }

    public function update(PaddleDiscountId $id, UpdateDiscountRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->discounts->update(
                $id->value,
                new UpdateDiscount(
                    amount:      $request->amount ?? $undef,
                    description: $request->description ?? $undef,
                    code:        $request->code ?? $undef,
                    usageLimit:  $request->usageLimit ?? $undef,
                )
            )
        );
    }

    public function archive(PaddleDiscountId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->discounts->update(
                $id->value,
                new UpdateDiscount(status: SdkDiscountStatus::from('archived'))
            )
        );
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->discounts->list()
        );

        return array_map(
            fn($sdkDiscount) => Discount::fromSdk($sdkDiscount),
            iterator_to_array($collection)
        );
    }
}
