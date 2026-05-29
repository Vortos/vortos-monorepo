<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Contract;

use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

interface StandaloneCustomerServiceInterface
{
    public function create(CreateCustomerRequest $request): PaddleCustomerId;

    public function get(PaddleCustomerId $id): Customer;

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void;

    public function archive(PaddleCustomerId $id): void;

    /** @return Customer[] */
    public function list(): array;
}
