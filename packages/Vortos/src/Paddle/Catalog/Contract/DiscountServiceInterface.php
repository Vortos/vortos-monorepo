<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Contract;

use Vortos\Paddle\Catalog\Discount;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\UpdateDiscountRequest;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

interface DiscountServiceInterface
{
    public function create(CreateDiscountRequest $request): PaddleDiscountId;

    public function get(PaddleDiscountId $id): Discount;

    public function update(PaddleDiscountId $id, UpdateDiscountRequest $request): void;

    public function archive(PaddleDiscountId $id): void;

    /** @return Discount[] */
    public function list(): array;
}
