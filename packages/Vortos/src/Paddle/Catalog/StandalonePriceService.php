<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Catalog\Contract\PriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandalonePriceServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Operation\UpdatePriceRequest;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class StandalonePriceService implements StandalonePriceServiceInterface
{
    public function __construct(
        private readonly Connection           $connection,
        private readonly PriceServiceInterface $transactional,
    ) {}

    public function create(CreatePriceRequest $request): PaddlePriceId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddlePriceId => $this->transactional->create($request)
        );
    }

    public function get(PaddlePriceId $id): Price
    {
        return $this->transactional->get($id);
    }

    public function update(PaddlePriceId $id, UpdatePriceRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function archive(PaddlePriceId $id): void
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
