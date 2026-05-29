<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Contract;

use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\Transaction\TransactionPreviewResult;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

interface ImmediateTransactionServiceInterface
{
    public function create(CreateTransactionRequest $request): PaddleTransactionId;

    public function get(PaddleTransactionId $id): Transaction;

    public function update(PaddleTransactionId $id, UpdateTransactionRequest $request): void;

    public function preview(CreateTransactionRequest $request): TransactionPreviewResult;

    public function getInvoicePdfUrl(PaddleTransactionId $id): string;

    /** @return Transaction[] */
    public function list(): array;
}
