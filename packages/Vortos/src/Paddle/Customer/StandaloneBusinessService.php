<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Customer\Contract\BusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneBusinessServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\Customer\Operation\UpdateBusinessRequest;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class StandaloneBusinessService implements StandaloneBusinessServiceInterface
{
    public function __construct(
        private readonly Connection              $connection,
        private readonly BusinessServiceInterface $transactional,
    ) {}

    public function create(CreateBusinessRequest $request): PaddleBusinessId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleBusinessId => $this->transactional->create($request)
        );
    }

    public function get(PaddleCustomerId $customerId, PaddleBusinessId $businessId): Business
    {
        return $this->transactional->get($customerId, $businessId);
    }

    public function update(PaddleCustomerId $customerId, PaddleBusinessId $businessId, UpdateBusinessRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($customerId, $businessId, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($customerId, $businessId, $request)
        );
    }

    public function archive(PaddleCustomerId $customerId, PaddleBusinessId $businessId): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->archive($customerId, $businessId);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->archive($customerId, $businessId)
        );
    }

    public function list(PaddleCustomerId $customerId): array
    {
        return $this->transactional->list($customerId);
    }
}
