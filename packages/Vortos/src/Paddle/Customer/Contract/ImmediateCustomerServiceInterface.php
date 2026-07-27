<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Contract;

use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

interface ImmediateCustomerServiceInterface
{
    public function create(CreateCustomerRequest $request): PaddleCustomerId;

    /**
     * The customer for this email address, creating one only if Paddle has none.
     *
     * Paddle has no "create if absent" endpoint and no lookup by email, so this is
     * create-and-recover: a conflict response names the existing customer, which is
     * the answer. Idempotent from the caller's point of view.
     */
    public function findOrCreate(CreateCustomerRequest $request): PaddleCustomerId;

    public function get(PaddleCustomerId $id): Customer;

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void;

    public function archive(PaddleCustomerId $id): void;

    /** @return Customer[] */
    public function list(): array;
}
