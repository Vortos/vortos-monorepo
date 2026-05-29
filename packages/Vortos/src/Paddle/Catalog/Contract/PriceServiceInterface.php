<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Contract;

use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Operation\UpdatePriceRequest;
use Vortos\Paddle\Catalog\Price;
use Vortos\Paddle\ValueObject\PaddlePriceId;

interface PriceServiceInterface
{
    public function create(CreatePriceRequest $request): PaddlePriceId;

    public function get(PaddlePriceId $id): Price;

    public function update(PaddlePriceId $id, UpdatePriceRequest $request): void;

    public function archive(PaddlePriceId $id): void;

    /** @return Price[] */
    public function list(): array;
}
