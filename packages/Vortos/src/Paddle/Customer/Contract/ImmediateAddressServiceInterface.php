<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Contract;

use Vortos\Paddle\Customer\Address;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\Customer\Operation\UpdateAddressRequest;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

interface ImmediateAddressServiceInterface
{
    public function create(CreateAddressRequest $request): PaddleAddressId;

    public function get(PaddleCustomerId $customerId, PaddleAddressId $addressId): Address;

    public function update(PaddleCustomerId $customerId, PaddleAddressId $addressId, UpdateAddressRequest $request): void;

    public function archive(PaddleCustomerId $customerId, PaddleAddressId $addressId): void;

    /** @return Address[] */
    public function list(PaddleCustomerId $customerId): array;
}
