<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Transaction;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\Transaction\TransactionalTransactionService;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\TransactionStatus;

final class TransactionalTransactionServiceTest extends TestCase
{
    private function makeTransaction(string $id = 'txn_test'): Transaction
    {
        return new Transaction(
            id:             PaddleTransactionId::of($id),
            customerId:     PaddleCustomerId::of('ctm_test'),
            subscriptionId: null,
            status:         TransactionStatus::Draft,
            currencyCode:   'USD',
            total:          '1200',
            billedAt:       null,
            createdAt:      new \DateTimeImmutable('2024-01-01'),
            updatedAt:      new \DateTimeImmutable('2024-01-02'),
            lineItems:      [],
        );
    }

    public function test_create_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('transaction.create', $this->arrayHasKey('customerId'));

        $reader  = $this->createMock(ImmediateTransactionServiceInterface::class);
        $service = new TransactionalTransactionService($outbox, $reader);
        $id      = $service->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_123'),
            items:      [new TransactionItemRequest(PaddlePriceId::of('pri_123'), 1)],
        ));

        $this->assertInstanceOf(PaddleTransactionId::class, $id);
    }

    public function test_get_delegates_to_reader(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateTransactionServiceInterface::class);
        $reader->expects($this->once())
               ->method('get')
               ->willReturn($this->makeTransaction('txn_abc'));

        $service     = new TransactionalTransactionService($outbox, $reader);
        $transaction = $service->get(PaddleTransactionId::of('txn_abc'));

        $this->assertSame('txn_abc', $transaction->id->value);
    }

    public function test_update_queues_to_outbox(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
               ->method('queue')
               ->with('transaction.update', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateTransactionServiceInterface::class);
        $service = new TransactionalTransactionService($outbox, $reader);
        $service->update(PaddleTransactionId::of('txn_123'), new UpdateTransactionRequest());
    }

    public function test_list_delegates_to_reader(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateTransactionServiceInterface::class);
        $reader->expects($this->once())
               ->method('list')
               ->willReturn([$this->makeTransaction('txn_1'), $this->makeTransaction('txn_2')]);

        $service      = new TransactionalTransactionService($outbox, $reader);
        $transactions = $service->list();

        $this->assertCount(2, $transactions);
        $this->assertContainsOnlyInstancesOf(Transaction::class, $transactions);
    }
}
