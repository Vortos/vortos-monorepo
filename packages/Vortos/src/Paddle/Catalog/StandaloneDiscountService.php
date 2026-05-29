<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Catalog\Contract\DiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandaloneDiscountServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\UpdateDiscountRequest;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

final class StandaloneDiscountService implements StandaloneDiscountServiceInterface
{
    public function __construct(
        private readonly Connection              $connection,
        private readonly DiscountServiceInterface $transactional,
    ) {}

    public function create(CreateDiscountRequest $request): PaddleDiscountId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleDiscountId => $this->transactional->create($request)
        );
    }

    public function get(PaddleDiscountId $id): Discount
    {
        return $this->transactional->get($id);
    }

    public function update(PaddleDiscountId $id, UpdateDiscountRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function archive(PaddleDiscountId $id): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->archive($id);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->archive($id)
        );
    }

    public function list(): array
    {
        return $this->transactional->list();
    }
}
