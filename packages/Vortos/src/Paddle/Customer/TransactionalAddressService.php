<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\Customer\Contract\AddressServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\Customer\Operation\UpdateAddressRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class TransactionalAddressService implements AddressServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface     $outbox,
        private readonly ImmediateAddressServiceInterface $reader,
    ) {}

    public function create(CreateAddressRequest $request): PaddleAddressId
    {
        $id = PaddleAddressId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('address.create', [
            'customerId'  => $request->customerId->value,
            'countryCode' => $request->countryCode,
            'firstLine'   => $request->firstLine,
        ]);

        return $id;
    }

    public function get(PaddleCustomerId $customerId, PaddleAddressId $addressId): Address
    {
        return $this->reader->get($customerId, $addressId);
    }

    public function update(PaddleCustomerId $customerId, PaddleAddressId $addressId, UpdateAddressRequest $request): void
    {
        $this->outbox->queue('address.update', [
            'customerId'  => $customerId->value,
            'id'          => $addressId->value,
            'firstLine'   => $request->firstLine,
            'city'        => $request->city,
        ]);
    }

    public function archive(PaddleCustomerId $customerId, PaddleAddressId $addressId): void
    {
        $this->outbox->queue('address.archive', [
            'customerId' => $customerId->value,
            'id'         => $addressId->value,
        ]);
    }

    public function list(PaddleCustomerId $customerId): array
    {
        return $this->reader->list($customerId);
    }
}
