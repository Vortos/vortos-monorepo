<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Transaction\Contract\AdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\StandaloneAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;

final class StandaloneAdjustmentService implements StandaloneAdjustmentServiceInterface
{
    public function __construct(
        private readonly Connection                $connection,
        private readonly AdjustmentServiceInterface $transactional,
    ) {}

    public function createRefund(CreateRefundRequest $request): PaddleAdjustmentId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->createRefund($request);
        }

        return $this->connection->transactional(
            fn(): PaddleAdjustmentId => $this->transactional->createRefund($request)
        );
    }

    public function createCredit(CreateCreditRequest $request): PaddleAdjustmentId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->createCredit($request);
        }

        return $this->connection->transactional(
            fn(): PaddleAdjustmentId => $this->transactional->createCredit($request)
        );
    }

    public function get(PaddleAdjustmentId $id): Adjustment
    {
        return $this->transactional->get($id);
    }

    public function list(): array
    {
        return $this->transactional->list();
    }
}
