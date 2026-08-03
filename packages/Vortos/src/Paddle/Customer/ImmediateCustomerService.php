<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Paddle\SDK\Resources\Customers\Operations\CreateCustomer;
use Paddle\SDK\Resources\Customers\Operations\UpdateCustomer;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\Exception\PaddleConflictException;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateCustomerService implements ImmediateCustomerServiceInterface
{
    /** Every Paddle customer id carries this prefix; nothing else does. */
    private const CUSTOMER_ID_PREFIX = 'ctm_';

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

    public function findOrCreate(CreateCustomerRequest $request): PaddleCustomerId
    {
        try {
            return $this->create($request);
        } catch (PaddleConflictException $e) {
            // Paddle already holds a customer for this address — which is the same
            // answer the caller asked for, so the conflict is a hit, not a failure.
            //
            // Only a customer id will do. The id is recovered from Paddle's free-text
            // detail and the caller bills whoever it names, so a conflict that names
            // some other kind of entity is not this situation and must not be treated
            // as one; like a conflict that names nothing, it goes out as a failure.
            $customerId = $e->conflictingEntityIdOfType(self::CUSTOMER_ID_PREFIX);
            if ($customerId === null) {
                throw $e;
            }

            return PaddleCustomerId::of($customerId);
        }
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
