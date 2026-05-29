<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Customer\Contract\AddressServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneAddressServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\Customer\Operation\UpdateAddressRequest;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class StandaloneAddressService implements StandaloneAddressServiceInterface
{
    public function __construct(
        private readonly Connection             $connection,
        private readonly AddressServiceInterface $transactional,
    ) {}

    public function create(CreateAddressRequest $request): PaddleAddressId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleAddressId => $this->transactional->create($request)
        );
    }

    public function get(PaddleCustomerId $customerId, PaddleAddressId $addressId): Address
    {
        return $this->transactional->get($customerId, $addressId);
    }

    public function update(PaddleCustomerId $customerId, PaddleAddressId $addressId, UpdateAddressRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($customerId, $addressId, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($customerId, $addressId, $request)
        );
    }

    public function archive(PaddleCustomerId $customerId, PaddleAddressId $addressId): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->archive($customerId, $addressId);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->archive($customerId, $addressId)
        );
    }

    public function list(PaddleCustomerId $customerId): array
    {
        return $this->transactional->list($customerId);
    }
}
