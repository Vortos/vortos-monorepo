<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Paddle\SDK\Entities\Shared\CountryCode;
use Paddle\SDK\Resources\Addresses\Operations\CreateAddress;
use Paddle\SDK\Resources\Addresses\Operations\UpdateAddress;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\Customer\Operation\UpdateAddressRequest;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateAddressService implements ImmediateAddressServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateAddressRequest $request): PaddleAddressId
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdk = $this->client->call(
            fn() => $this->client->sdk()->addresses->create(
                $request->customerId->value,
                new CreateAddress(
                    countryCode:  CountryCode::from($request->countryCode),
                    description:  $request->description ?? $undef,
                    firstLine:    $request->firstLine ?? $undef,
                    city:         $request->city ?? $undef,
                    postalCode:   $request->postalCode ?? $undef,
                )
            )
        );

        return PaddleAddressId::of($sdk->id);
    }

    public function get(PaddleCustomerId $customerId, PaddleAddressId $addressId): Address
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->addresses->get($customerId->value, $addressId->value)
        );

        return Address::fromSdk($sdk);
    }

    public function update(PaddleCustomerId $customerId, PaddleAddressId $addressId, UpdateAddressRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->addresses->update(
                $customerId->value,
                $addressId->value,
                new UpdateAddress(
                    description:  $request->description ?? $undef,
                    firstLine:    $request->firstLine ?? $undef,
                    city:         $request->city ?? $undef,
                    postalCode:   $request->postalCode ?? $undef,
                    countryCode:  $request->countryCode !== null
                                      ? CountryCode::from($request->countryCode)
                                      : $undef,
                )
            )
        );
    }

    public function archive(PaddleCustomerId $customerId, PaddleAddressId $addressId): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->addresses->archive($customerId->value, $addressId->value)
        );
    }

    public function list(PaddleCustomerId $customerId): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->addresses->list($customerId->value)
        );

        return array_map(
            fn($sdk) => Address::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
