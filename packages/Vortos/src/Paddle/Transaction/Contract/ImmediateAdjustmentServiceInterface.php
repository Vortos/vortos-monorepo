<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Contract;

use Vortos\Paddle\Transaction\Adjustment;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;

interface ImmediateAdjustmentServiceInterface
{
    public function createRefund(CreateRefundRequest $request): PaddleAdjustmentId;

    public function createCredit(CreateCreditRequest $request): PaddleAdjustmentId;

    public function get(PaddleAdjustmentId $id): Adjustment;

    /** @return Adjustment[] */
    public function list(): array;
}
