<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\Customer\Contract\BusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\Customer\Operation\UpdateBusinessRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class TransactionalBusinessService implements BusinessServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface      $outbox,
        private readonly ImmediateBusinessServiceInterface $reader,
    ) {}

    public function create(CreateBusinessRequest $request): PaddleBusinessId
    {
        $id = PaddleBusinessId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('business.create', [
            'customerId' => $request->customerId->value,
            'name'       => $request->name,
        ]);

        return $id;
    }

    public function get(PaddleCustomerId $customerId, PaddleBusinessId $businessId): Business
    {
        return $this->reader->get($customerId, $businessId);
    }

    public function update(PaddleCustomerId $customerId, PaddleBusinessId $businessId, UpdateBusinessRequest $request): void
    {
        $this->outbox->queue('business.update', [
            'customerId' => $customerId->value,
            'id'         => $businessId->value,
            'name'       => $request->name,
        ]);
    }

    public function archive(PaddleCustomerId $customerId, PaddleBusinessId $businessId): void
    {
        $this->outbox->queue('business.archive', [
            'customerId' => $customerId->value,
            'id'         => $businessId->value,
        ]);
    }

    public function list(PaddleCustomerId $customerId): array
    {
        return $this->reader->list($customerId);
    }
}
