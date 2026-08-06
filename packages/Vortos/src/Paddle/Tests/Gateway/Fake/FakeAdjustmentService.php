<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Gateway\Fake;

use Vortos\Paddle\Transaction\Adjustment;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;

final class FakeAdjustmentService implements ImmediateAdjustmentServiceInterface
{
    /** @var list<CreateRefundRequest> */
    public array $refunds = [];

    public function createRefund(CreateRefundRequest $request): PaddleAdjustmentId
    {
        $this->refunds[] = $request;

        return PaddleAdjustmentId::of('adj_fake');
    }

    public function createCredit(CreateCreditRequest $request): PaddleAdjustmentId
    {
        throw new \LogicException('The gateway adapter does not issue credits.');
    }

    public function get(PaddleAdjustmentId $id): Adjustment
    {
        throw new \LogicException('The gateway adapter does not read adjustments.');
    }

    public function list(): array
    {
        throw new \LogicException('The gateway adapter does not list adjustments.');
    }
}
