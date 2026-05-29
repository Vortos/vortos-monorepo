<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Catalog\Contract\ProductServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandaloneProductServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class StandaloneProductService implements StandaloneProductServiceInterface
{
    public function __construct(
        private readonly Connection             $connection,
        private readonly ProductServiceInterface $transactional,
    ) {}

    public function create(CreateProductRequest $request): PaddleProductId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleProductId => $this->transactional->create($request)
        );
    }

    public function get(PaddleProductId $id): Product
    {
        return $this->transactional->get($id);
    }

    public function update(PaddleProductId $id, UpdateProductRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function archive(PaddleProductId $id): void
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
