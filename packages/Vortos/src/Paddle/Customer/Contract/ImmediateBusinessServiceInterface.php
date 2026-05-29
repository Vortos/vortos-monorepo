<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Contract;

use Vortos\Paddle\Customer\Business;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\Customer\Operation\UpdateBusinessRequest;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

interface ImmediateBusinessServiceInterface
{
    public function create(CreateBusinessRequest $request): PaddleBusinessId;

    public function get(PaddleCustomerId $customerId, PaddleBusinessId $businessId): Business;

    public function update(PaddleCustomerId $customerId, PaddleBusinessId $businessId, UpdateBusinessRequest $request): void;

    public function archive(PaddleCustomerId $customerId, PaddleBusinessId $businessId): void;

    /** @return Business[] */
    public function list(PaddleCustomerId $customerId): array;
}
