<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Contract;

use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\Catalog\Product;
use Vortos\Paddle\ValueObject\PaddleProductId;

interface ImmediateProductServiceInterface
{
    public function create(CreateProductRequest $request): PaddleProductId;

    public function get(PaddleProductId $id): Product;

    public function update(PaddleProductId $id, UpdateProductRequest $request): void;

    public function archive(PaddleProductId $id): void;

    /** @return Product[] */
    public function list(): array;
}
