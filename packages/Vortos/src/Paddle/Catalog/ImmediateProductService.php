<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Paddle\SDK\Resources\Products\Operations\CreateProduct;
use Paddle\SDK\Resources\Products\Operations\UpdateProduct;
use Paddle\SDK\Entities\Shared\Status;
use Paddle\SDK\Entities\Shared\TaxCategory;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class ImmediateProductService implements ImmediateProductServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateProductRequest $request): PaddleProductId
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkProduct = $this->client->call(
            fn() => $this->client->sdk()->products->create(
                new CreateProduct(
                    name:        $request->name,
                    taxCategory: TaxCategory::from($request->taxCategory),
                    description: $request->description ?? $undef,
                    imageUrl:    $request->imageUrl ?? $undef,
                )
            )
        );

        return PaddleProductId::of($sdkProduct->id);
    }

    public function get(PaddleProductId $id): Product
    {
        $sdkProduct = $this->client->call(
            fn() => $this->client->sdk()->products->get($id->value)
        );

        return Product::fromSdk($sdkProduct);
    }

    public function update(PaddleProductId $id, UpdateProductRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->products->update(
                $id->value,
                new UpdateProduct(
                    name:        $request->name ?? $undef,
                    description: $request->description ?? $undef,
                    imageUrl:    $request->imageUrl ?? $undef,
                    taxCategory: $request->taxCategory !== null
                                     ? TaxCategory::from($request->taxCategory)
                                     : $undef,
                )
            )
        );
    }

    public function archive(PaddleProductId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->products->archive($id->value)
        );
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->products->list()
        );

        return array_map(
            fn($sdkProduct) => Product::fromSdk($sdkProduct),
            iterator_to_array($collection)
        );
    }
}
