<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Customer\Contract\CustomerServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class StandaloneCustomerService implements StandaloneCustomerServiceInterface
{
    public function __construct(
        private readonly Connection              $connection,
        private readonly CustomerServiceInterface $transactional,
    ) {}

    public function create(CreateCustomerRequest $request): PaddleCustomerId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleCustomerId => $this->transactional->create($request)
        );
    }

    public function get(PaddleCustomerId $id): Customer
    {
        return $this->transactional->get($id);
    }

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function archive(PaddleCustomerId $id): void
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
