<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

use Doctrine\DBAL\Connection;
use Vortos\Paddle\Transaction\Contract\StandaloneTransactionServiceInterface;
use Vortos\Paddle\Transaction\Contract\TransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class StandaloneTransactionService implements StandaloneTransactionServiceInterface
{
    public function __construct(
        private readonly Connection                   $connection,
        private readonly TransactionServiceInterface  $transactional,
    ) {}

    public function create(CreateTransactionRequest $request): PaddleTransactionId
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactional->create($request);
        }

        return $this->connection->transactional(
            fn(): PaddleTransactionId => $this->transactional->create($request)
        );
    }

    public function get(PaddleTransactionId $id): Transaction
    {
        return $this->transactional->get($id);
    }

    public function update(PaddleTransactionId $id, UpdateTransactionRequest $request): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->transactional->update($id, $request);
            return;
        }

        $this->connection->transactional(
            fn() => $this->transactional->update($id, $request)
        );
    }

    public function preview(CreateTransactionRequest $request): TransactionPreviewResult
    {
        return $this->transactional->preview($request);
    }

    public function getInvoicePdfUrl(PaddleTransactionId $id): string
    {
        return $this->transactional->getInvoicePdfUrl($id);
    }

    public function list(): array
    {
        return $this->transactional->list();
    }
}
