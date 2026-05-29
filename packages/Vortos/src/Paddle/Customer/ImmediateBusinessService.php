<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Paddle\SDK\Resources\Businesses\Operations\CreateBusiness;
use Paddle\SDK\Resources\Businesses\Operations\UpdateBusiness;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\Customer\Operation\UpdateBusinessRequest;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateBusinessService implements ImmediateBusinessServiceInterface
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function create(CreateBusinessRequest $request): PaddleBusinessId
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdk = $this->client->call(
            fn() => $this->client->sdk()->businesses->create(
                $request->customerId->value,
                new CreateBusiness(
                    name:          $request->name,
                    companyNumber: $request->companyNumber ?? $undef,
                    taxIdentifier: $request->taxIdentifier ?? $undef,
                )
            )
        );

        return PaddleBusinessId::of($sdk->id);
    }

    public function get(PaddleCustomerId $customerId, PaddleBusinessId $businessId): Business
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->businesses->get($customerId->value, $businessId->value)
        );

        return Business::fromSdk($sdk);
    }

    public function update(PaddleCustomerId $customerId, PaddleBusinessId $businessId, UpdateBusinessRequest $request): void
    {
        $undef = new \Paddle\SDK\Undefined();

        $this->client->call(
            fn() => $this->client->sdk()->businesses->update(
                $customerId->value,
                $businessId->value,
                new UpdateBusiness(
                    name:          $request->name ?? $undef,
                    companyNumber: $request->companyNumber ?? $undef,
                    taxIdentifier: $request->taxIdentifier ?? $undef,
                )
            )
        );
    }

    public function archive(PaddleCustomerId $customerId, PaddleBusinessId $businessId): void
    {
        $this->client->call(
            fn() => $this->client->sdk()->businesses->archive($customerId->value, $businessId->value)
        );
    }

    public function list(PaddleCustomerId $customerId): array
    {
        $collection = $this->client->call(
            fn() => $this->client->sdk()->businesses->list($customerId->value)
        );

        return array_map(
            fn($sdk) => Business::fromSdk($sdk),
            iterator_to_array($collection)
        );
    }
}
