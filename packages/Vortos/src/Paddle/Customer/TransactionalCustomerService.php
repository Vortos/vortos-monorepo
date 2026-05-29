<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\Customer\Contract\CustomerServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class TransactionalCustomerService implements CustomerServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface      $outbox,
        private readonly ImmediateCustomerServiceInterface $reader,
    ) {}

    public function create(CreateCustomerRequest $request): PaddleCustomerId
    {
        $id = PaddleCustomerId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('customer.create', [
            'email'  => $request->email,
            'name'   => $request->name,
            'locale' => $request->locale,
        ]);

        return $id;
    }

    public function get(PaddleCustomerId $id): Customer
    {
        return $this->reader->get($id);
    }

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void
    {
        $this->outbox->queue('customer.update', [
            'id'     => $id->value,
            'name'   => $request->name,
            'email'  => $request->email,
            'locale' => $request->locale,
        ]);
    }

    public function archive(PaddleCustomerId $id): void
    {
        $this->outbox->queue('customer.archive', ['id' => $id->value]);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
