<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Paddle\SDK\Resources\Customers\Operations\CreateCustomer;
use Paddle\SDK\Resources\Customers\Operations\UpdateCustomer;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateCustomerService implements ImmediateCustomerServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateCustomerRequest $request): PaddleCustomerId
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkCustomer = $this->client->call(
            fn() => $this->client->sdk()->customers->create(
                new CreateCustomer(
                    email:  $request->email,
                    name:   $request->name ?? $undef,
                    locale: $request->locale ?? $undef,
                )
            )
        );

        return PaddleCustomerId::of($sdkCustomer->id);
    }

    public function get(PaddleCustomerId $id): Customer
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->customers->get($id->value)
        );

        return Customer::fromSdk($sdk);
    }

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->customers->update(
                $id->value,
                new UpdateCustomer(
                    name:   $request->name ?? $undef,
                    email:  $request->email ?? $undef,
                    locale: $request->locale ?? $undef,
                )
            )
        );
    }

    public function archive(PaddleCustomerId $id): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->customers->archive($id->value)
        );
    }

    public function list(): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->customers->list()
        );

        return array_map(
            fn($sdk) => Customer::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
