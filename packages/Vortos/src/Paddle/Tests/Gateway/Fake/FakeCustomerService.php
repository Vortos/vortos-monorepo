<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Gateway\Fake;

use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

/**
 * A customer service that answers without a network.
 *
 * Methods the gateway adapter does not use throw rather than returning a
 * plausible empty value: a fake that quietly answers a question the code under
 * test was not supposed to ask makes the test pass for the wrong reason.
 */
final class FakeCustomerService implements ImmediateCustomerServiceInterface
{
    /** @var list<CreateCustomerRequest> */
    public array $created = [];

    public function create(CreateCustomerRequest $request): PaddleCustomerId
    {
        return $this->findOrCreate($request);
    }

    public function findOrCreate(CreateCustomerRequest $request): PaddleCustomerId
    {
        $this->created[] = $request;

        return PaddleCustomerId::of('ctm_fake');
    }

    public function get(PaddleCustomerId $id): Customer
    {
        throw new \LogicException('The gateway adapter does not read customers.');
    }

    public function update(PaddleCustomerId $id, UpdateCustomerRequest $request): void
    {
        throw new \LogicException('The gateway adapter does not update customers.');
    }

    public function archive(PaddleCustomerId $id): void
    {
        throw new \LogicException('The gateway adapter does not archive customers.');
    }

    public function list(): array
    {
        throw new \LogicException('The gateway adapter does not list customers.');
    }
}
